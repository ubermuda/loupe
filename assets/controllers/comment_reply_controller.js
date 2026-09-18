import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggle', 'form'];

    toggle() {
        this.formTarget.hidden = !this.formTarget.hidden;
        this.toggleTarget.setAttribute(
            'aria-expanded',
            this.formTarget.hidden ? 'false' : 'true',
        );
    }
}
