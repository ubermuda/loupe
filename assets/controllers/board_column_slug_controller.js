import { Controller } from '@hotwired/stimulus';

/**
 * Shows the slug a column rename will write, while the label is typed.
 *
 * The server derives the slug, so the preview reloads a Turbo frame from the
 * preview endpoint rather than guessing in the browser. A browser guess would
 * disagree with the server's transliteration for some scripts.
 */

const DELAY_MILLISECONDS = 250;

export default class extends Controller {
    static targets = ['input', 'frame'];
    static values = { url: String };

    disconnect() {
        clearTimeout(this.timeout);
    }

    change() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => {
            const url = new URL(this.urlValue, window.location.href);
            url.searchParams.set('label', this.inputTarget.value);
            this.frameTarget.src = url.toString();
        }, DELAY_MILLISECONDS);
    }
}
