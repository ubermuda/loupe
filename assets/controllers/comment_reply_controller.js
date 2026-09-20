import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'form'];

    toggle() {
        this.formTarget.hidden = !this.formTarget.hidden;
        this.toggleTarget.setAttribute(
            'aria-expanded',
            this.formTarget.hidden ? 'false' : 'true',
        );
        if (this.formTarget.hidden) {
            this.toggleTarget.focus();

            return;
        }
        // The reader pressed Reply to write, so the caret starts in the field
        // rather than one Tab away from it.
        this.formTarget.querySelector('textarea')?.focus();
    }
}
