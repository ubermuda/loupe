import { Controller } from '@hotwired/stimulus';

/**
 * Shows the saved label on a form's submit button for a moment, when the
 * server re-renders the form with the saved flag. data-turbo-submits-with on
 * the button shows the busy label while the form submits.
 */
export default class extends Controller {
    static targets = ['button'];
    static values = {
        saved: Boolean,
        savedLabel: String,
        duration: { type: Number, default: 3000 },
    };

    connect() {
        if (!this.savedValue || !this.hasButtonTarget) {
            return;
        }
        const button = this.buttonTarget;
        const label = button.innerHTML;
        button.textContent = this.savedLabelValue;
        this.timer = setTimeout(() => {
            button.innerHTML = label;
        }, this.durationValue);
        // A page restored from the cache must not show it again.
        this.savedValue = false;
    }

    disconnect() {
        clearTimeout(this.timer);
    }
}
