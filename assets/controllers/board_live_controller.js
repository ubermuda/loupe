import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on, status } from '../lib/live.js';

/**
 * Shows each card change on the board as it happens. A burst of messages for
 * one card costs one placement fetch, a card in a drag waits until the drag
 * settles, and a card another person changed is marked for a moment.
 *
 * `own` never skips the fetch. A member can send another tab's origin, so the
 * flag may only suppress the mark.
 */

const SETTLE_MILLISECONDS = 150;
const BUSY_RETRY_MILLISECONDS = 200;
// A stalled request would hold the queue for every card.
const FETCH_TIMEOUT_MILLISECONDS = 10000;
const FLASH_MILLISECONDS = 1500;
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const FLASH_CLASS = 'lp-board-card--flash';

export default class extends Controller {
    static targets = ['paused'];
    static values = {
        placement: String,
        placeholder: String,
        manifest: String,
    };

    initialize() {
        this.liveState = 'off';
    }

    connect() {
        this.pending = new Map();
        this.expected = new Map();
        this.flashes = new Map();
        this.queue = Promise.resolve();
        this.onPlaced = (event) => this.placed(event.detail ?? {});
        this.onMissed = (event) => this.missed(event.detail ?? {});
        document.addEventListener('board:placed', this.onPlaced);
        document.addEventListener('board:place-missed', this.onMissed);
        this.unsubscribe = on(
            'board.card_changed',
            (change) => this.receive(change),
            { onReconnect: () => this.catchUp() },
        );
        this.stopStatus = status((state) => {
            this.liveState = state;
            this.pausedTargets.forEach((element) => this.showStatus(element));
        });
    }

    disconnect() {
        this.unsubscribe?.();
        this.stopStatus?.();
        this.manifestAbort?.abort();
        this.manifestAbort = undefined;
        document.removeEventListener('board:placed', this.onPlaced);
        document.removeEventListener('board:place-missed', this.onMissed);
        this.pending.forEach((entry) => clearTimeout(entry.timer));
        this.pending.clear();
        this.expected.clear();
        this.flashes.forEach((timer) => clearTimeout(timer));
        this.flashes.clear();
    }

    pausedTargetConnected(element) {
        this.showStatus(element);
    }

    /** The live region stays in place, so a screen reader hears the new text. */
    showStatus(element) {
        const text =
            this.liveState === 'paused' ? (element.dataset.message ?? '') : '';
        if (element.textContent !== text) {
            element.textContent = text;
        }
    }

    /**
     * After a reconnect, compares the page with the board manifest and places
     * each card that the page missed or shows out of order. A newer reconnect
     * aborts an older read.
     */
    async catchUp() {
        this.manifestAbort?.abort();
        const abort = new AbortController();
        this.manifestAbort = abort;
        const timeout = setTimeout(
            () => abort.abort(),
            FETCH_TIMEOUT_MILLISECONDS,
        );
        let manifest = null;
        try {
            // A read of the board's card digests, with no form to submit.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.manifestValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: abort.signal,
            });
            if (response.ok) {
                manifest = await response.json();
            }
        } catch {
            manifest = null;
        } finally {
            clearTimeout(timeout);
        }
        if (this.manifestAbort !== abort) {
            return;
        }
        this.manifestAbort = undefined;

        const structure =
            this.element.querySelector('#board')?.dataset.boardStructureDigest;
        if (!isManifest(manifest) || manifest.structure !== structure) {
            this.reload();

            return;
        }
        const shown = new Map();
        this.element
            .querySelectorAll('.lp-board-card[data-card-digest]')
            .forEach((card) =>
                shown.set(card.dataset.cardId, card.dataset.cardDigest),
            );
        const listed = new Map(
            manifest.cards.map(([cardId, , columnId], index) => [
                cardId,
                { columnId, index },
            ]),
        );
        const removed = [...shown.keys()].filter(
            (cardId) => !listed.has(cardId),
        );
        const moved = new Set();
        this.element
            .querySelectorAll(
                '.lp-board__group[data-column], .lp-board-lane__cell[data-column]',
            )
            .forEach((group) => {
                const cards = [...group.querySelectorAll('.lp-board-card')]
                    .map((card) => ({
                        cardId: card.dataset.cardId,
                        ...listed.get(card.dataset.cardId),
                    }))
                    .filter((card) => card.columnId === group.dataset.column);
                outOfOrder(cards).forEach((cardId) => moved.add(cardId));
            });
        // Two lane cells of one column can each keep their order while the
        // list, which runs through the whole column, does not.
        const rows = [
            ...this.element.querySelectorAll(
                '.lp-board-list__row[data-card-id]',
            ),
        ]
            .filter((row) => listed.has(row.dataset.cardId))
            .map((row) => ({
                cardId: row.dataset.cardId,
                ...listed.get(row.dataset.cardId),
            }));
        outOfOrder(rows).forEach((cardId) => moved.add(cardId));
        const changed = manifest.cards
            .filter(
                ([cardId, digest]) =>
                    shown.get(cardId) !== digest || moved.has(cardId),
            )
            .map(([cardId]) => cardId);
        [...removed, ...changed].forEach((cardId) =>
            this.receive({ cardId, local: false, own: false }),
        );
    }

    receive(change) {
        const cardId = change.cardId;
        if (typeof cardId !== 'string' || cardId === '') {
            return;
        }
        if (!this.pending.has(cardId)) {
            this.pending.set(cardId, {
                timer: undefined,
                inFlight: false,
                again: false,
                remote: false,
            });
        }
        const entry = this.pending.get(cardId);
        entry.remote ||= !change.local && !change.own;
        if (entry.inFlight) {
            entry.again = true;

            return;
        }
        this.schedule(cardId, entry, SETTLE_MILLISECONDS);
    }

    schedule(cardId, entry, delay) {
        clearTimeout(entry.timer);
        entry.timer = setTimeout(
            () => this.fetchPlacement(cardId, entry),
            delay,
        );
    }

    /** One placement at a time, so an older answer never renders last. */
    fetchPlacement(cardId, entry) {
        entry.timer = undefined;
        entry.inFlight = true;
        const run = this.queue.then(() => this.place(cardId, entry));
        this.queue = run.catch(() => {});
    }

    async place(cardId, entry) {
        if (this.pending.get(cardId) !== entry) {
            return;
        }
        const card = document.getElementById(`board-card-${cardId}`);
        if (card !== null && this.busy(card)) {
            entry.inFlight = false;
            entry.again = false;
            this.schedule(cardId, entry, BUSY_RETRY_MILLISECONDS);

            return;
        }

        this.expected.set(cardId, {
            digest: card?.dataset.cardDigest,
            group: card?.parentElement,
            previous: previousCardId(card),
            remote: entry.remote,
        });
        entry.remote = false;
        entry.again = false;

        let html = null;
        const abort = new AbortController();
        const timeout = setTimeout(
            () => abort.abort(),
            FETCH_TIMEOUT_MILLISECONDS,
        );
        try {
            // A read of one card's placement, with no form to submit.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.urlFor(cardId), {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
                signal: abort.signal,
            });
            const type = response.headers.get('Content-Type') ?? '';
            if (response.ok && type.startsWith(STREAM_TYPE)) {
                html = await response.text();
            }
        } catch {
            html = null;
        } finally {
            clearTimeout(timeout);
        }

        entry.inFlight = false;
        if (this.pending.get(cardId) !== entry) {
            return;
        }
        const current = document.getElementById(`board-card-${cardId}`);
        if (current !== null && this.busy(current)) {
            entry.remote ||= this.expected.get(cardId)?.remote ?? false;
            entry.again = false;
            this.expected.delete(cardId);
            this.schedule(cardId, entry, BUSY_RETRY_MILLISECONDS);

            return;
        }
        if (html === null) {
            this.expected.delete(cardId);
            this.reload();
        } else {
            renderStreamMessage(html);
        }
        if (entry.again) {
            entry.again = false;
            this.schedule(cardId, entry, SETTLE_MILLISECONDS);
        } else {
            this.pending.delete(cardId);
        }
    }

    /** A card in a drag, or with a move the server has not placed yet. */
    busy(card) {
        return (
            card.getAttribute('aria-busy') === 'true' ||
            card.classList.contains('lp-board-card--dragging')
        );
    }

    urlFor(cardId) {
        return this.placementValue.replace(
            this.placeholderValue,
            encodeURIComponent(cardId),
        );
    }

    placed({ cardId, removed }) {
        const expected = this.expected.get(cardId);
        if (expected === undefined) {
            return;
        }
        this.expected.delete(cardId);
        const card = document.getElementById(`board-card-${cardId}`);
        if (
            removed ||
            !expected.remote ||
            card === null ||
            (card.dataset.cardDigest === expected.digest &&
                card.parentElement === expected.group &&
                previousCardId(card) === expected.previous)
        ) {
            return;
        }
        this.flash(cardId, card);
    }

    flash(cardId, card) {
        clearTimeout(this.flashes.get(cardId));
        card.classList.remove(FLASH_CLASS);
        // Reading the layout makes the browser start the animation again.
        void card.offsetWidth;
        card.classList.add(FLASH_CLASS);
        this.flashes.set(
            cardId,
            setTimeout(() => {
                this.flashes.delete(cardId);
                card.classList.remove(FLASH_CLASS);
            }, FLASH_MILLISECONDS),
        );
    }

    missed({ cardId }) {
        this.expected.delete(cardId);
        this.reload();
    }

    reload() {
        this.dispatch('reload');
    }
}

function isManifest(manifest) {
    return (
        typeof manifest?.structure === 'string' &&
        Array.isArray(manifest.cards) &&
        manifest.cards.every(
            (entry) =>
                Array.isArray(entry) &&
                typeof entry[0] === 'string' &&
                typeof entry[1] === 'string' &&
                typeof entry[2] === 'string',
        )
    );
}

function previousCardId(card) {
    let sibling = card?.previousElementSibling;
    while (sibling && !sibling.matches('.lp-board-card')) {
        sibling = sibling.previousElementSibling;
    }

    return sibling?.dataset.cardId ?? null;
}

/**
 * The ids of the cards, in page order, that fall outside a longest run whose
 * manifest indexes rise. Only those cards moved, so only those need a fetch.
 */
function outOfOrder(cards) {
    const tails = [];
    const before = [];
    cards.forEach(({ index }, position) => {
        let low = 0;
        let high = tails.length;
        while (low < high) {
            const middle = Math.floor((low + high) / 2);
            if (cards[tails[middle]].index < index) {
                low = middle + 1;
            } else {
                high = middle;
            }
        }
        before[position] = low > 0 ? tails[low - 1] : -1;
        tails[low] = position;
    });
    const kept = new Set();
    for (
        let position = tails.at(-1) ?? -1;
        position !== -1;
        position = before[position]
    ) {
        kept.add(position);
    }

    return cards
        .filter((card, position) => !kept.has(position))
        .map(({ cardId }) => cardId);
}
