import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

// A burst of worker run changes costs one reload. A card or column drag, a
// pending card move or column reorder, or an open column menu or dialog defers
// it. A steady stream still reloads once per max wait.
const RELOAD_DELAY_MS = 300;
const RELOAD_MAX_WAIT_MS = 2000;
const BUSY_SELECTOR = [
    '.lp-board--dragging',
    '[data-board-drag-target="card"][aria-busy="true"]',
    '.lp-board__column--dragging',
    '[data-board-columns-target="form"][aria-busy="true"]',
    '.lp-board__column-menu-panel:not([hidden])',
    'dialog[open]',
].join(', ');

/**
 * Reloads the board frame when the Mercure hub reports a column change or a
 * worker run change, and after each reconnect for any change it missed.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = { board: String };

    connect() {
        this.adoptSource();
        this.unsubscribe = on(
            ['board.columns_changed', 'worker_run.changed'],
            () => this.reload(),
            {
                onReconnect: () => this.reload(),
                onOpen: () =>
                    this.element.setAttribute(
                        'data-board-refresh-connected',
                        '',
                    ),
                onError: () =>
                    this.element.removeAttribute(
                        'data-board-refresh-connected',
                    ),
            },
        );
    }

    disconnect() {
        clearTimeout(this.reloadTimer);
        this.pendingSince = undefined;
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        this.element.removeAttribute('data-board-refresh-connected');
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
