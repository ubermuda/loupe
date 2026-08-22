/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Swaps a form's status line once any field differs from what the server
 * rendered. Text only — the submit button stays live, so a page whose JS never
 * arrives still submits and merely shows the on-load wording.
 */
export default class extends Controller {
    static targets = ['note'];
    static values = { dirty: String };

    connect() {
        this.cleanText = this.noteTarget.textContent.trim();
        this.form = this.element.querySelector('form');
        this.initial = this.serialize();
    }

    check() {
        const dirty = this.serialize() !== this.initial;
        this.noteTarget.textContent = dirty ? this.dirtyValue : this.cleanText;
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
