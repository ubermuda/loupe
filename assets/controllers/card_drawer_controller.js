import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['dialog', 'frame', 'loading', 'error'];

    connect() {
        this.invoker = null;
        this.focusFrameOnLoad = false;
        this.onBeforeCache = () => this.#reset();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    prepare(event) {
        this.invoker = event.currentTarget;
        this.#startLoading();
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }
    }

    loaded(event) {
        if (event.target !== this.frameTarget || !this.dialogTarget.open)
            return;
        this.loadingTarget.hidden = true;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = false;
        // A form inside the frame fires turbo:frame-load too. Focusing then
        // takes the caret away from whatever the reader is typing.
        if (!this.focusFrameOnLoad) return;
        this.focusFrameOnLoad = false;
        const focusTarget = this.frameTarget.querySelector(
            '[data-panel-tabs-target="tab"][aria-selected="true"], a, button',
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

    #startLoading() {
        this.focusFrameOnLoad = true;
        this.loadingTarget.hidden = false;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = true;
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
        this.errorTarget.hidden = true;
        this.frameTarget.removeAttribute('src');
        this.frameTarget.replaceChildren();
        this.invoker = null;
        this.focusFrameOnLoad = false;
    }
}
