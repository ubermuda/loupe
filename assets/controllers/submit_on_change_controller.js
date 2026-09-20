import { Controller } from '@hotwired/stimulus';

/**
 * Submits the form as soon as a control in it changes, so a select needs no
 * button beside it. The form keeps a submit inside a noscript block, which is
 * what a reader without this controller uses.
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
