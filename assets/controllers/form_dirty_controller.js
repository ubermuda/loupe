/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Swaps a form's status line once any field differs from what the server
 * rendered. Text only — the submit button stays live, so a page whose JS never
 * arrives still submits and merely shows the on-load wording.
 */
export default class extends Controller {
    static targets = ['note'];
    static values = { clean: String, dirty: String, submitted: Boolean };

    connect() {
        this.form = this.element.querySelector('form');
        this.initial = this.serialize();
    }

    check() {
        // A rejected submission is redisplayed with the typed values, which were
        // never persisted. The server already rendered it dirty, and no amount
        // of further typing makes it clean again.
        if (this.submittedValue) {
            return;
        }

        this.noteTarget.textContent =
            this.serialize() === this.initial
                ? this.cleanValue
                : this.dirtyValue;
    }

    serialize() {
        if (!this.form) {
            return '';
        }

        return Array.from(new FormData(this.form).entries())
            .map(([name, value]) => `${name}=${value}`)
            .join('&');
    }
}
