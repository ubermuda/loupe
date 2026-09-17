import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['frame', 'loading', 'error'];
    static values = { url: String };

    open() {
        this.loadingTarget.hidden = false;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = true;
        if (this.frameTarget.src) {
            this.frameTarget.reload();
        } else {
            this.frameTarget.src = this.urlValue;
        }
    }

    loaded() {
        this.loadingTarget.hidden = true;
        this.frameTarget.hidden = false;
    }

    received(event) {
        const response = event.detail.fetchResponse;
        if (!response.succeeded || !response.isHTML) this.failed(event);
    }

    failed(event) {
        event.preventDefault();
        this.loadingTarget.hidden = true;
        this.errorTarget.hidden = false;
        this.frameTarget.hidden = true;
    }
}
