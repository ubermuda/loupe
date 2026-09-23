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
    static values = { url: String, frames: Array, whole: Boolean };

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
        if (this.wholeValue) {
            window.Turbo?.visit(window.location.href, { action: 'replace' });
            return;
        }
        const others = this.framesValue
            .map((id) => document.getElementById(id))
            .filter((frame) => frame !== null);
        // A control the reader is using keeps its value until the next signal.
        others
            .filter((frame) => !frame.contains(document.activeElement))
            .concat(this.frameTarget)
            .forEach((frame) => this.load(frame));
    }

    load(frame) {
        // A frame with no src has nothing to reload, and one given a src loads it.
        if (frame.getAttribute('src') === null) {
            frame.setAttribute('src', this.urlValue || window.location.href);
            return;
        }
        frame.reload();
    }
}
