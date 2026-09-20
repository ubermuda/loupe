// [Claude] The dialog element is animated exclusively with WAAPI (element.animate), not CSS animations.
// CSS animations cache per-element state and silently fail to restart after a close/reopen cycle even
// with a reflow trick. WAAPI always creates a fresh animation object and never has this stale-state problem.
// Do NOT add CSS animation: rules targeting .mp-dialog-box.is-opening or .mp-dialog-box.is-closing.
// Only the ::backdrop animations remain as CSS (WAAPI cannot animate the backdrop).
import { Controller } from '@hotwired/stimulus';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

const OPEN_KEYFRAMES = [
    { opacity: 0, transform: 'translateY(-16px)' },
    { opacity: 1, transform: 'translateY(0)' },
];
const CLOSE_KEYFRAMES = [
    { opacity: 1, transform: 'translateY(0)' },
    { opacity: 0, transform: 'translateY(-16px)' },
];
const OPEN_KEYFRAMES_MOBILE = [
    { transform: 'translateY(100%)' },
    { transform: 'translateY(0)' },
];
const CLOSE_KEYFRAMES_MOBILE = [
    { transform: 'translateY(0)' },
    { transform: 'translateY(100%)' },
];
const OPEN_KEYFRAMES_DRAWER = [
    { transform: 'translateX(100%)' },
    { transform: 'translateX(0)' },
];
const CLOSE_KEYFRAMES_DRAWER = [
    { transform: 'translateX(0)' },
    { transform: 'translateX(100%)' },
];

// A drawer slides for 200ms on the ease curve, in and out.
const DRAWER_TIMING = { duration: 200, easing: 'ease' };

const isMobile = () => window.innerWidth < 640;

/** A modeless drawer only pays off where the page beside it is still usable. */
const MODELESS_MIN_WIDTH = 1024;

export default class extends Controller {
    static targets = ['dialog'];
    static values = { reopen: Boolean, drawer: Boolean, modeless: Boolean };

    connect() {
        this.onEscapeKey = (event) => {
            if (event.key === 'Escape' && this.dialogTarget.open) {
                event.preventDefault();
                this.close();
            }
        };
        document.addEventListener(
            'turbo:before-stream-render',
            this.#onBeforeStreamRender,
        );

        // Opt-in: re-open the dialog on connect, e.g. after a 422 re-render of
        // a page whose modal form failed validation, so the field error is
        // visible immediately instead of the modal silently closing.
        if (this.reopenValue) {
            this.open();
        }
    }

    disconnect() {
        this.closeRequest = null;
        this.closingAnimation = null;
        document.removeEventListener('keydown', this.onEscapeKey);
        document.removeEventListener(
            'turbo:before-stream-render',
            this.#onBeforeStreamRender,
        );
    }

    dialogTargetConnected(dialog) {
        dialog.addEventListener('click', this.#onDialogClick);
    }

    dialogTargetDisconnected(dialog) {
        dialog.removeEventListener('click', this.#onDialogClick);
    }

    #onDialogClick = (event) => {
        if (event.target === this.dialogTarget) {
            this.close(event);
        }
    };

    open(event) {
        event?.preventDefault();
        this.closeRequest = null;
        this.closingAnimation = null;
        const dialog = this.dialogTarget;
        if (!dialog.open) {
            this.returnFocusTo = document.activeElement;
        }
        // A modeless dialog leaves the rest of the page live. It reports no
        // cancel event, so Escape is handled here instead.
        this.modeless =
            this.modelessValue && window.innerWidth >= MODELESS_MIN_WIDTH;
        if (!dialog.open) {
            if (this.modeless) {
                dialog.show();
                document.addEventListener('keydown', this.onEscapeKey);
            } else {
                dialog.showModal();
            }
        }
        dialog.classList.remove('is-closing');
        dialog.classList.add('is-opening');
        // Cancel any leftover animations and start a fresh one every time.
        // CSS animations cache state per-element and don't reliably restart
        // after a close/reopen cycle; WAAPI always creates a new animation object.
        dialog.getAnimations().forEach((a) => a.cancel());
        if (prefersReducedMotion()) {
            dialog.classList.remove('is-opening');
            return;
        }
        const keyframes = this.drawerValue
            ? OPEN_KEYFRAMES_DRAWER
            : isMobile()
              ? OPEN_KEYFRAMES_MOBILE
              : OPEN_KEYFRAMES;
        dialog.animate(
            keyframes,
            this.drawerValue
                ? DRAWER_TIMING
                : { duration: 220, easing: 'ease-out' },
        );
    }

    close(event) {
        event?.preventDefault();
        const dialog = this.dialogTarget;
        if (!dialog.open) return;
        document.removeEventListener('keydown', this.onEscapeKey);
        const request = {};
        this.closeRequest = request;
        this.#animateOutAsync().then(() => {
            if (this.closeRequest === request && dialog.isConnected) {
                this.closeRequest = null;
                dialog.close();
                this.restoreFocus();
            }
        });
    }

    restoreFocus() {
        if (this.returnFocusTo?.isConnected) {
            this.returnFocusTo.focus();
        }
        this.returnFocusTo = null;
    }

    // [Claude] turbo:submit-end fires AFTER Turbo has already applied stream mutations. If a stream
    // removes or replaces an ancestor element containing this open dialog, the dialog is detached before
    // turbo:submit-end fires and the close animation never runs. Fix: intercept turbo:before-stream-render
    // instead and replace event.detail.render with an async function — Turbo awaits it, so the DOM mutation
    // is held until the close animation completes.
    #onBeforeStreamRender = (event) => {
        const stream = event.detail.newStream;
        const action = stream.getAttribute('action');
        if (action !== 'remove' && action !== 'replace') return;

        const target = document.getElementById(stream.getAttribute('target'));
        if (!target?.contains(this.element)) return;
        if (!this.dialogTarget.open) return;

        const originalRender = event.detail.render;
        event.detail.render = async (streamElement) => {
            await this.#animateOutAsync();
            await originalRender(streamElement);
        };
    };

    #animateOutAsync() {
        const dialog = this.dialogTarget;
        dialog.classList.remove('is-opening');
        dialog.classList.add('is-closing');
        dialog.getAnimations().forEach((a) => a.cancel());

        if (prefersReducedMotion()) {
            this.closingAnimation = null;
            dialog.classList.remove('is-closing');
            return Promise.resolve();
        }

        const keyframes = this.drawerValue
            ? CLOSE_KEYFRAMES_DRAWER
            : isMobile()
              ? CLOSE_KEYFRAMES_MOBILE
              : CLOSE_KEYFRAMES;
        const anim = dialog.animate(keyframes, {
            ...(this.drawerValue
                ? DRAWER_TIMING
                : { duration: 180, easing: 'ease-in' }),
            fill: 'forwards',
        });
        this.closingAnimation = anim;
        const cleanup = () => {
            if (this.closingAnimation === anim) {
                this.closingAnimation = null;
                dialog.classList.remove('is-closing');
            }
            anim.cancel();
        };

        return anim.finished.then(cleanup, cleanup);
    }
}
