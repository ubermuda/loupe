import { Controller } from '@hotwired/stimulus';

// A burst of reload requests costs one reload. A card drag, a pending card move
// or an open dialog defers it. A steady stream still reloads once per max wait.
const RELOAD_DELAY_MS = 300;
const RELOAD_MAX_WAIT_MS = 2000;
const BUSY_SELECTOR = [
    '.lp-board--dragging',
    '[data-board-drag-target="card"][aria-busy="true"]',
    'dialog[open]',
].join(', ');

/**
 * Reloads the board frame when another controller asks for a reload.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = { board: String };

    connect() {
        this.adoptSource();
    }

    disconnect() {
        clearTimeout(this.reloadTimer);
        this.pendingSince = undefined;
    }

    reload() {
        this.pendingSince ??= Date.now();
        const untilMaxWait =
            this.pendingSince + RELOAD_MAX_WAIT_MS - Date.now();
        this.schedule(Math.max(0, Math.min(RELOAD_DELAY_MS, untilMaxWait)));
    }

    schedule(delay) {
        clearTimeout(this.reloadTimer);
        this.reloadTimer = setTimeout(() => this.reloadUnlessBusy(), delay);
    }

    reloadUnlessBusy() {
        // A reload mid-drag swaps the elements a drag controller holds, one
        // during a move or reorder can land its old order after the new one,
        // and one under an open menu or dialog closes it and drops typed text.
        if (this.frameTarget.querySelector(BUSY_SELECTOR) !== null) {
            this.schedule(RELOAD_DELAY_MS);
            return;
        }
        this.pendingSince = undefined;
        this.frameTarget.reload();
    }

    // Only reload() renders by morph, and it needs a src. A frame given a src
    // loads it and clears `complete`, unless it is disabled at that moment.
    adoptSource() {
        const frame = this.frameTarget;
        if (frame.hasAttribute('complete')) {
            return;
        }
        frame.setAttribute('disabled', '');
        frame.setAttribute('src', this.boardValue);
        frame.setAttribute('complete', '');
        frame.removeAttribute('disabled');
    }
}
