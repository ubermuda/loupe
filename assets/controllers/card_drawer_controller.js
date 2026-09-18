import ModalController from './modal_controller.js';
import { prefersReducedMotion } from '../lib/smooth_scroll.js';

/* stimulusFetch: 'eager' */
export default class extends ModalController {
    static targets = ['dialog', 'frame', 'loading', 'error'];
    static values = {
        reopen: Boolean,
        drawer: { type: Boolean, default: true },
    };

    connect() {
        super.connect();
        this.invoker = null;
        this.onBeforeCache = () => this.#reset();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        super.disconnect();
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    dialogTargetConnected(dialog) {
        super.dialogTargetConnected(dialog);
        dialog.addEventListener('close', this.#onClosed);
    }

    dialogTargetDisconnected(dialog) {
        super.dialogTargetDisconnected(dialog);
        dialog.removeEventListener('close', this.#onClosed);
    }

    /** A card link keeps its own click, which Turbo turns into the frame load. */
    prepare(event) {
        this.invoker = event.currentTarget;
        this.#startLoading();
        if (!this.dialogTarget.open) {
            this.open();
        }
        this.returnFocusTo = this.invoker;
    }

    loaded(event) {
        if (event.target !== this.frameTarget || !this.dialogTarget.open)
            return;
        const replacing = this.frameTarget.hidden === false;
        this.loadingTarget.hidden = true;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = false;
        if (replacing && !prefersReducedMotion()) {
            this.frameTarget.animate?.([{ opacity: 0 }, { opacity: 1 }], {
                duration: 150,
                easing: 'ease',
            });
        }
        const focusTarget = this.frameTarget.querySelector(
            'form input:not([type="hidden"]), [data-panel-tabs-target="tab"][aria-selected="true"], a, button',
        );
        focusTarget?.focus();
    }

    received(event) {
        if (event.target !== this.frameTarget) return;
        const response = event.detail.fetchResponse;
        if (!response.succeeded || !response.isHTML) this.failed(event);
    }

    failed(event) {
        if (event.target !== this.frameTarget || !this.dialogTarget.open)
            return;
        event.preventDefault();
        this.loadingTarget.hidden = true;
        this.frameTarget.hidden = true;
        this.errorTarget.hidden = false;
        this.errorTarget.querySelector('button').focus();
    }

    retry() {
        this.#startLoading();
        this.loadingTarget.querySelector('button').focus();
        this.frameTarget.reload();
    }

    /** The board listens for card-drawer:saved, so a saved card shows on it at once. */
    submitted(event) {
        if (!event.detail.success) return;
        this.dispatch('saved');
    }

    close(event) {
        if (!this.dialogTarget.open) return;
        this.#repairReturnFocus();
        super.close(event);
    }

    #startLoading() {
        this.loadingTarget.hidden = false;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = true;
    }

    // A board reload replaces the link that opened the drawer; its twin keeps the place.
    #repairReturnFocus() {
        const href = this.invoker?.getAttribute('href');
        if (href && !this.returnFocusTo?.isConnected) {
            this.returnFocusTo = [
                ...this.element.querySelectorAll('a[href]'),
            ].find((link) => link.getAttribute('href') === href);
        }
    }

    #onClosed = () => {
        this.loadingTarget.hidden = false;
        this.errorTarget.hidden = true;
        this.frameTarget.removeAttribute('src');
        this.frameTarget.replaceChildren();
        this.invoker = null;
    };

    #reset() {
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        this.#onClosed();
    }
}
