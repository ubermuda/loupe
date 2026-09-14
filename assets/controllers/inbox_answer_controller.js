import { Controller } from '@hotwired/stimulus';

/**
 * Copies the ticked options of an inbox question into the answer form.
 *
 * The option controls reuse the decision block markup and post nothing
 * themselves. Every change writes the ticked indexes into the hidden
 * selectedOptions field, and the owner submits with the button, because an
 * answer can close an ask and wake the agent that waits on it.
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
}
