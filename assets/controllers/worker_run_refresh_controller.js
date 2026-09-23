import { Controller } from '@hotwired/stimulus';
import { subscribe } from '../lib/mercure.js';

/** A report often arrives with the next state of the same run close behind it. */
export const DEBOUNCE_MILLISECONDS = 300;

/**
 * Reloads a frame of worker runs when the Mercure hub reports a change, and
 * after each reconnect for any change it missed. An open run drawer holds the
 * reload until it closes, so the reader keeps what they are reading.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = { url: String };

    connect() {
        this.hasOpened = false;
        this.held = false;
        // A dialog's close event does not bubble, so the listener captures it.
        this.onDialogClose = () => {
            if (this.held) {
                this.reload();
            }
        };
        this.element.addEventListener('close', this.onDialogClose, true);
        this.unsubscribe = subscribe(
            'worker_run.changed',
            () => this.schedule(),
            {
                onOpen: () => {
                    if (this.hasOpened) {
                        this.schedule();
                    }
                    this.hasOpened = true;
                },
            },
        );
    }

    disconnect() {
        clearTimeout(this.timeout);
        this.element.removeEventListener('close', this.onDialogClose, true);
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    schedule() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.reload(), DEBOUNCE_MILLISECONDS);
    }

    reload() {
        if (this.frameTarget.querySelector('dialog[open]') !== null) {
            this.held = true;
            return;
        }
        this.held = false;
        // A frame with no src has nothing to reload, and one given a src loads it.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.setAttribute(
                'src',
                this.urlValue || window.location.href,
            );
            return;
        }
        this.frameTarget.reload();
    }
}
