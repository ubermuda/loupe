import { Controller } from '@hotwired/stimulus';

/**
 * Posts a reviewer's answer to a decision block.
 *
 * The radios live in the document's stored HTML, which no form theme rendered
 * and no CSRF token can be baked into, so they are grouping-only inputs that
 * post nothing themselves — clicking one copies its decision id and option
 * index into this form's hidden fields, exactly as the comment composer fills
 * its anchor fields, and Turbo submits it.
 */
export default class extends Controller {
    static targets = ['form', 'decisionId', 'optionIndex'];

    select(event) {
        const radio = event.target;
        if (!radio.matches('input[type="radio"][data-decision-option]')) {
            return;
        }

        const block = radio.closest('[data-decision-id]');
        if (!block || !this.hasFormTarget) {
            return;
        }

        this.decisionIdTarget.value = block.dataset.decisionId;
        this.optionIndexTarget.value = radio.value;
        // requestSubmit(), never submit(): submit() fires no submit event, so
        // csrf_protection_controller.js's document-level listener never runs the
        // double-submit and every password-login session gets a 403 — while the
        // tests, which have no JS, stay green.
        this.formTarget.requestSubmit();
    }
}
