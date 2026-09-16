import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['dialog', 'frame', 'loading'];

    connect() {
        this.invoker = null;
        this.onBeforeCache = () => this.#reset();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    prepare(event) {
        this.invoker = event.currentTarget;
        this.loadingTarget.hidden = false;
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }
    }

    loaded() {
        this.loadingTarget.hidden = true;
        const focusTarget = this.frameTarget.querySelector(
            '[data-panel-tabs-target="tab"][aria-selected="true"], a, button',
        );
        focusTarget?.focus();
    }

    cancel(event) {
        event.preventDefault();
        this.close(event);
    }

    backdropClick(event) {
        if (event.target === this.dialogTarget) {
            this.close(event);
        }
    }

    close(event) {
        event?.preventDefault();
        if (!this.dialogTarget.open) {
            return;
        }

        const returnTarget = this.invoker;
        this.#reset();
        if (returnTarget?.isConnected) {
            returnTarget.focus();
        }
    }

    #reset() {
        if (this.dialogTarget.open) {
            this.dialogTarget.close();
        }
        this.loadingTarget.hidden = false;
        this.frameTarget.removeAttribute('src');
        this.frameTarget.replaceChildren();
        this.invoker = null;
    }
}
