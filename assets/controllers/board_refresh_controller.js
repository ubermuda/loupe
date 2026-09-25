import { Controller } from '@hotwired/stimulus';
import { subscribe } from '../lib/mercure.js';

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
        this.hasOpened = false;
        this.unsubscribe = subscribe(
            ['board.columns_changed', 'worker_run.changed'],
            () => this.reload(),
            {
                onOpen: () => {
                    if (this.hasOpened) {
                        this.reload();
                    }
                    this.hasOpened = true;
                    this.element.setAttribute(
                        'data-board-refresh-connected',
                        '',
                    );
                },
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
        // A frame with no src has nothing to reload, and one given a src loads it.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.src = this.boardValue;
        } else {
            this.frameTarget.reload();
        }
    }
}
