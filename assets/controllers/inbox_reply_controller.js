import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['error'];

    start() {
        this.errorTarget.hidden = true;
    }

    failed(event) {
        event.preventDefault();
        this.errorTarget.hidden = false;
    }

    response(event) {
        if (event.detail.fetchResponse.statusCode >= 500) {
            this.failed(event);
        }
    }
}
