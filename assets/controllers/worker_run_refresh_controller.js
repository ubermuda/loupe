import { Controller } from '@hotwired/stimulus';
import { subscribe } from '../lib/mercure.js';

/** A report often arrives with the next state of the same run close behind it. */
export const DEBOUNCE_MILLISECONDS = 300;

/** Longer than the 300 ms a filter change waits before it submits. */
export const BLUR_RELOAD_MILLISECONDS = 1000;

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
        this.blurTimeouts = [];
        this.heldFrames = new Set();
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
        this.blurTimeouts.forEach((timeout) => clearTimeout(timeout));
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
        [this.frameTarget, ...others].forEach((frame) => {
            if (
                frame !== this.frameTarget &&
                frame.contains(document.activeElement)
            ) {
                this.holdUntilBlur(frame);
                return;
            }
            this.load(frame);
        });
    }

    /**
     * A control the reader is using keeps its value. The frame reloads once
     * focus leaves it, after a filter change has had time to submit.
     */
    holdUntilBlur(frame) {
        if (this.heldFrames.has(frame)) {
            return;
        }
        this.heldFrames.add(frame);
        frame.addEventListener(
            'focusout',
            () => {
                this.heldFrames.delete(frame);
                this.blurTimeouts.push(
                    setTimeout(() => {
                        if (frame.contains(document.activeElement)) {
                            this.holdUntilBlur(frame);
                            return;
                        }
                        this.load(frame);
                    }, BLUR_RELOAD_MILLISECONDS),
                );
            },
            { once: true },
        );
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
