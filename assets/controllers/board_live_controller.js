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
const FLASH_MILLISECONDS = 1500;
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const FLASH_CLASS = 'lp-board-card--flash';

export default class extends Controller {
    static targets = ['paused'];
    static values = { placement: String, placeholder: String };

    initialize() {
        this.liveState = 'off';
    }

    connect() {
        this.pending = new Map();
        this.expected = new Map();
        this.onPlaced = (event) => this.placed(event.detail ?? {});
        this.onMissed = (event) => this.missed(event.detail ?? {});
        document.addEventListener('board:placed', this.onPlaced);
        document.addEventListener('board:place-missed', this.onMissed);
        this.unsubscribe = on('board.card_changed', (change) =>
            this.receive(change),
        );
        this.stopStatus = status((state) => {
            this.liveState = state;
            this.pausedTargets.forEach((element) => this.showStatus(element));
        });
    }

    disconnect() {
        this.unsubscribe?.();
        this.stopStatus?.();
        document.removeEventListener('board:placed', this.onPlaced);
        document.removeEventListener('board:place-missed', this.onMissed);
        this.pending.forEach((entry) => clearTimeout(entry.timer));
        this.pending.clear();
        this.expected.clear();
    }

    pausedTargetConnected(element) {
        this.showStatus(element);
    }

    showStatus(element) {
        element.hidden = this.liveState !== 'paused';
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

    async fetchPlacement(cardId, entry) {
        entry.timer = undefined;
        const card = document.getElementById(`board-card-${cardId}`);
        if (card !== null && this.busy(card)) {
            this.schedule(cardId, entry, BUSY_RETRY_MILLISECONDS);

            return;
        }

        entry.inFlight = true;
        this.expected.set(cardId, {
            digest: card?.dataset.cardDigest,
            remote: entry.remote,
        });
        entry.remote = false;

        let html = null;
        try {
            const response = await fetch(this.urlFor(cardId), {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
            });
            const type = response.headers.get('Content-Type') ?? '';
            if (response.ok && type.startsWith(STREAM_TYPE)) {
                html = await response.text();
            }
        } catch {
            html = null;
        }

        entry.inFlight = false;
        if (this.pending.get(cardId) !== entry) {
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
            card.dataset.cardDigest === expected.digest
        ) {
            return;
        }
        card.classList.add(FLASH_CLASS);
        setTimeout(
            () => card.classList.remove(FLASH_CLASS),
            FLASH_MILLISECONDS,
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
