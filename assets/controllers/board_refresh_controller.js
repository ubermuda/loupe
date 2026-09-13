import { Controller } from '@hotwired/stimulus';
import { subscribe } from '../lib/mercure.js';

/**
 * Reloads the board frame when the Mercure hub reports a column change, and
 * after each reconnect for any change it missed.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = { board: String };

    connect() {
        this.hasOpened = false;
        this.unsubscribe = subscribe(
            'board.columns_changed',
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
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        this.element.removeAttribute('data-board-refresh-connected');
    }

    reload() {
        // A frame with no src has nothing to reload, and one given a src loads it.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.src = this.boardValue;
        } else {
            this.frameTarget.reload();
        }
    }
}
