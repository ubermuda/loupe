import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

/**
 * Reloads the board frame when the Mercure hub reports a column change, and
 * after each reconnect for any change it missed.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = { board: String };

    connect() {
        this.adoptSource();
        this.unsubscribe = on('board.columns_changed', () => this.reload(), {
            onReconnect: () => this.reload(),
            onOpen: () =>
                this.element.setAttribute('data-board-refresh-connected', ''),
            onError: () =>
                this.element.removeAttribute('data-board-refresh-connected'),
        });
    }

    disconnect() {
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        this.element.removeAttribute('data-board-refresh-connected');
    }

    reload() {
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
