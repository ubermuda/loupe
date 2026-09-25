import { Controller } from '@hotwired/stimulus';

/**
 * Shows the saved label on a form's submit button for a moment, when the
 * server re-renders the form with the saved flag. data-turbo-submits-with on
 * the button shows the busy label while the form submits.
 */
export default class extends Controller {
    static targets = ['button', 'status'];
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
        // A screen reader announces a change to a live region it already
        // knows, so the text arrives after the re-rendered region.
        this.announceTimer = setTimeout(
            () => this.announce(this.savedLabelValue),
            100,
        );
        this.timer = setTimeout(() => {
            button.innerHTML = label;
            this.announce('');
        }, this.durationValue);
        // A page restored from the cache must not show it again.
        this.savedValue = false;
    }

    disconnect() {
        clearTimeout(this.announceTimer);
        clearTimeout(this.timer);
    }

    announce(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }
}
