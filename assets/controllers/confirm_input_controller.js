import { Controller } from '@hotwired/stimulus';

/**
 * Enables the submit target only while the input target's value exactly
 * matches the expected value (e.g. type-the-project-name confirmation).
 *
 * Usage:
 *   <div data-controller="confirm-input" data-confirm-input-expected-value="my-project">
 *     <input data-confirm-input-target="input" data-action="input->confirm-input#check">
 *     <button data-confirm-input-target="submit" disabled>Delete</button>
 *   </div>
 */
export default class extends Controller {
    static targets = ['input', 'submit'];
    static values = { expected: String };

    connect() {
        this.check();
    }

    check() {
        // Byte-for-byte match — the server compares the untrimmed value too.
        this.submitTarget.disabled =
            this.inputTarget.value !== this.expectedValue;
    }
}
