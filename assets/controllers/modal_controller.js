// [Claude] The dialog element is animated exclusively with WAAPI (element.animate), not CSS animations.
// CSS animations cache per-element state and silently fail to restart after a close/reopen cycle even
// with a reflow trick. WAAPI always creates a fresh animation object and never has this stale-state problem.
// Do NOT add CSS animation: rules targeting .mp-dialog-box.is-opening or .mp-dialog-box.is-closing.
// Only the ::backdrop animations remain as CSS (WAAPI cannot animate the backdrop).
import { Controller } from '@hotwired/stimulus';

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

const isMobile = () => window.innerWidth < 640;

export default class extends Controller {
    static targets = ['dialog'];

    connect() {
        document.addEventListener(
            'turbo:before-stream-render',
            this.#onBeforeStreamRender,
        );
    }

    disconnect() {
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
        event.preventDefault();
        const dialog = this.dialogTarget;
        dialog.showModal();
        dialog.classList.add('is-opening');
        // Cancel any leftover animations and start a fresh one every time.
        // CSS animations cache state per-element and don't reliably restart
        // after a close/reopen cycle; WAAPI always creates a new animation object.
        dialog.getAnimations().forEach((a) => a.cancel());
        const keyframes = isMobile() ? OPEN_KEYFRAMES_MOBILE : OPEN_KEYFRAMES;
        dialog.animate(keyframes, { duration: 220, easing: 'ease-out' });
    }

    close(event) {
        event?.preventDefault();
        this.#animateOutAsync().then(() => this.dialogTarget.close());
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

        const keyframes = isMobile() ? CLOSE_KEYFRAMES_MOBILE : CLOSE_KEYFRAMES;
        const anim = dialog.animate(keyframes, {
            duration: 180,
            easing: 'ease-in',
            fill: 'forwards',
        });

        return anim.finished
            .then(() => {
                dialog.classList.remove('is-closing');
                anim.cancel(); // release fill-forwards so the element isn't frozen
            })
            .catch(() => {
                dialog.classList.remove('is-closing');
            });
    }
}
