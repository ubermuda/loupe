import { Controller } from '@hotwired/stimulus';

/** Reloads one frame when something outside it changes the page's data. */
export default class extends Controller {
    static targets = ['frame'];

    reload() {
        // A frame with no src has nothing to reload, and one given a src loads it.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.setAttribute('src', window.location.href);
            return;
        }
        this.frameTarget.reload();
    }
}
