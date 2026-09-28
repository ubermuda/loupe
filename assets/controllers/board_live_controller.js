import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on, status } from '../lib/live.js';

/**
 * Shows each card change on the board as it happens. A burst of messages for
 * one card costs one placement fetch, a card in a drag waits until the drag
 * settles, and a card another person changed is marked for a moment. A failed
 * placement retries with a growing wait, and after the last retry the card is
 * marked stale. A reconnect places each card the page missed, and reloads the
 * board only when its structure changed.
 *
 * `own` never skips the fetch. A member can send another tab's origin, so the
 * flag may only suppress the mark.
 */

const SETTLE_MILLISECONDS = 150;
const BUSY_RETRY_MILLISECONDS = 200;
// A stalled request would hold the queue for every card.
const FETCH_TIMEOUT_MILLISECONDS = 10000;
const RETRY_MILLISECONDS = [1000, 3000, 9000];
const RETRY_JITTER = 0.2;
const FLASH_MILLISECONDS = 1500;
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const FLASH_CLASS = 'lp-board-card--flash';
// A lane epic has no card face. The head of its lane stands for it.
const STALE_MARKS = [
    [
        (cardId) => document.getElementById(`board-card-${cardId}`),
        'lp-board-card--stale',
    ],
    [
        (cardId) => document.getElementById(`board-row-${cardId}`),
        'lp-board-list__row--stale',
    ],
    [
        (cardId) =>
            document
                .getElementById(`board-lane-${cardId}`)
                ?.querySelector('.lp-board-lane__head') ?? null,
        'lp-board-lane__head--stale',
    ],
];
const RETRY = 'retry';
const STALE = 'stale';

export default class extends Controller {
    static targets = ['paused'];
    static values = {
        placement: String,
        placeholder: String,
        stale: String,
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
            ['board.card_changed', 'worker_run.card_warning_changed'],
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
        // A lane epic has no card face, only a list row, so it counts for the
        // list order alone.
        const listed = new Map(
            manifest.cards.map(([cardId, , columnId, laneHead], index) => [
                cardId,
                { columnId, index, laneHead: laneHead === true },
            ]),
        );
        const faces = manifest.cards.filter(([, , , laneHead]) => !laneHead);
        const removed = [...shown.keys()].filter(
            (cardId) => !listed.has(cardId) || listed.get(cardId).laneHead,
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
                    .filter(
                        (card) =>
                            !card.laneHead &&
                            card.columnId === group.dataset.column,
                    );
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
        // A lane head has no face, and the lane head stream keeps its list
        // row's digest current.
        const rowDigests = new Map();
        this.element
            .querySelectorAll('.lp-board-list__row[data-card-digest]')
            .forEach((row) =>
                rowDigests.set(row.dataset.cardId, row.dataset.cardDigest),
            );
        const changed = manifest.cards
            .filter(([cardId, digest, , laneHead]) => {
                if (moved.has(cardId)) {
                    return true;
                }
                if (!laneHead) {
                    return shown.get(cardId) !== digest;
                }

                return (
                    rowDigests.has(cardId) && rowDigests.get(cardId) !== digest
                );
            })
            .map(([cardId]) => cardId);
        const queued = [...removed, ...changed];
        // Any placement rewrites every history link, so one card is enough.
        if (queued.length === 0 && staleHistory(manifest.terminalTotals)) {
            if (faces.length === 0) {
                this.reload();

                return;
            }
            queued.push(faces[0][0]);
        }
        queued.forEach((cardId) =>
            this.receive({ cardId, local: false, own: false }),
        );
    }

    receive(change) {
        const cardId = change.cardId;
        if (typeof cardId !== 'string' || cardId === '') {
            return;
        }
        const entry = this.entryFor(cardId);
        entry.attempts = 0;
        entry.remote ||= !change.local && !change.own;
        if (entry.inFlight) {
            entry.again = true;

            return;
        }
        this.schedule(cardId, entry, SETTLE_MILLISECONDS);
    }

    /**
     * An entry lives until its card is placed or marked stale, so the count
     * of failed attempts survives a render that reports a miss later.
     */
    entryFor(cardId) {
        if (!this.pending.has(cardId)) {
            this.pending.set(cardId, {
                timer: undefined,
                inFlight: false,
                again: false,
                remote: false,
                attempts: 0,
            });
        }

        return this.pending.get(cardId);
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
        let failure = RETRY;
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
            } else if (!response.ok && !this.transient(response.status)) {
                failure = STALE;
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
        if (entry.again) {
            entry.again = false;
            this.schedule(cardId, entry, SETTLE_MILLISECONDS);
        }
        if (html === null) {
            entry.remote ||= this.expected.get(cardId)?.remote ?? false;
            this.expected.delete(cardId);
            if (entry.timer === undefined) {
                this.fail(cardId, entry, failure);
            }
        } else {
            // A miss can report inside this call or on a later frame.
            renderStreamMessage(html);
        }
    }

    /** A 403 or a 404 means the board is off or access is gone, so a retry cannot help. */
    transient(status) {
        return status >= 500 || status === 429 || status === 408;
    }

    fail(cardId, entry, failure) {
        entry.attempts += 1;
        if (failure === STALE || entry.attempts > RETRY_MILLISECONDS.length) {
            this.pending.delete(cardId);
            this.markStale(cardId);

            return;
        }
        const wait = RETRY_MILLISECONDS[entry.attempts - 1];
        this.schedule(cardId, entry, wait * (1 + RETRY_JITTER * Math.random()));
    }

    markStale(cardId) {
        STALE_MARKS.forEach(([find, className]) => {
            const element = find(cardId);
            if (element === null || element.hasAttribute('data-board-stale')) {
                return;
            }
            element.classList.add(className);
            element.setAttribute('data-board-stale', '');
            element.title = this.staleValue;
            const text = document.createElement('span');
            text.className = 'sr-only';
            text.dataset.boardStaleText = '';
            text.textContent = this.staleValue;
            element.append(text);
        });
    }

    /** The morph of fresh markup drops the mark too; this covers any other render. */
    clearStale(cardId) {
        STALE_MARKS.forEach(([find, className]) => {
            const element = find(cardId);
            if (element === null || !element.hasAttribute('data-board-stale')) {
                return;
            }
            element.classList.remove(className);
            element.removeAttribute('data-board-stale');
            element.removeAttribute('title');
            element.querySelector('[data-board-stale-text]')?.remove();
        });
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
        this.clearStale(cardId);
        const entry = this.pending.get(cardId);
        if (entry !== undefined) {
            entry.attempts = 0;
            if (!entry.inFlight && entry.timer === undefined) {
                this.pending.delete(cardId);
            }
        }
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

    /** A newer fetch or a waiting one supersedes the attempt that missed. */
    missed({ cardId }) {
        if (typeof cardId !== 'string' || cardId === '') {
            return;
        }
        const remote = this.expected.get(cardId)?.remote ?? false;
        this.expected.delete(cardId);
        const entry = this.entryFor(cardId);
        entry.remote ||= remote;
        if (entry.inFlight || entry.timer !== undefined) {
            return;
        }
        this.fail(cardId, entry, RETRY);
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
                typeof entry[2] === 'string' &&
                (entry.length === 3 ||
                    (entry.length === 4 && entry[3] === true)),
        )
    );
}

/** A terminal column whose history link on the page shows another total. */
function staleHistory(totals) {
    return Object.entries(totals ?? {}).some(([columnId, total]) => {
        const link = document.getElementById(`board-history-${columnId}`);

        return link !== null && link.dataset.historyTotal !== String(total);
    });
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
