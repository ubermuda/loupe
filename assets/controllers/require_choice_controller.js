import { Controller } from '@hotwired/stimulus';

/**
 * Enables the submit target only while the choice target holds a value. The
 * server still refuses an empty or foreign choice; this saves a round trip.
 *
 * Usage:
 *   <form data-controller="require-choice">
 *     <select data-require-choice-target="choice" data-action="change->require-choice#check">
 *     <button data-require-choice-target="submit">Allow</button>
 *   </form>
 */
export default class extends Controller {
    static targets = ['choice', 'submit'];

    connect() {
        this.check();
    }

    check() {
        // No choice on the page means nothing to pick, so the button stays live.
        this.submitTarget.disabled =
            this.hasChoiceTarget && '' === this.choiceTarget.value;
    }
}
