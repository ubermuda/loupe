import { Controller } from '@hotwired/stimulus';

/**
 * Copies the ticked options of an inbox question into the answer form.
 *
 * The option controls reuse the decision block markup. They post under a name
 * outside the form, so the form ignores them and reads the hidden
 * selectedOptions field, which every change fills with the ticked indexes.
 * The owner then submits with the button.
 */
export default class extends Controller {
    static targets = ['option', 'selectedOptions'];

    select() {
        if (!this.hasSelectedOptionsTarget) {
            return;
        }

        this.selectedOptionsTarget.value = this.optionTargets
            .filter((option) => option.checked)
            .map((option) => option.value)
            .join(',');
    }

    // A radio cannot be unticked by a click, so a text-only answer needs this.
    clear() {
        this.optionTargets.forEach((option) => {
            option.checked = false;
        });
        this.select();
    }
}
