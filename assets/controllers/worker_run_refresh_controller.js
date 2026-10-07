import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

/** A report often arrives with the next state of the same run close behind it. */
export const DEBOUNCE_MILLISECONDS = 300;

/**
 * Reloads a frame of worker runs when the Mercure hub reports a change, and
 * after each reconnect for any change it missed. An open run drawer holds the
 * reload until it closes, so the reader keeps what they are reading. With a
 * project value, a change that names another project is ignored.
 */
export default class extends Controller {
    static targets = ['frame'];
    static values = {
        url: String,
        frames: Array,
        whole: Boolean,
        project: String,
        events: { type: Array, default: ['worker_run.changed'] },
    };

    connect() {
        this.held = false;
        // A dialog's close event does not bubble, so the listener captures it.
        this.onDialogClose = () => {
            if (this.held) {
                this.reload();
            }
        };
        this.element.addEventListener('close', this.onDialogClose, true);
        this.unsubscribe = on(this.eventsValue, (data) => this.receive(data), {
            onReconnect: () => this.schedule(),
        });
    }

    disconnect() {
        clearTimeout(this.timeout);
        this.element.removeEventListener('close', this.onDialogClose, true);
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    receive(data) {
        const projectId = data?.projectId;
        if (this.projectValue && projectId && projectId !== this.projectValue) {
            return;
        }
        this.schedule();
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
        [this.frameTarget, ...others].forEach((frame) => this.load(frame));
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
