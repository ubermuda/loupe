import { Controller } from '@hotwired/stimulus';

/** The top bar sits outside the review block, so its button asks the dialog there by a window event. */
export default class extends Controller {
    open(event) {
        event.preventDefault();
        window.dispatchEvent(new CustomEvent('review:finish'));
    }
}
