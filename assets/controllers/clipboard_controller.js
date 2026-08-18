import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button'];
    static values = {
        text: String,
        copiedLabel: { type: String, default: 'Copied!' },
    };

    connect() {
        // A Turbo morph can reconnect while "Copied!" is still showing, so that
        // label is never captured as the one to restore.
        const label = this.hasButtonTarget ? this.buttonTarget.textContent : '';
        if (label !== this.copiedLabelValue) {
            this.originalLabel = label;
        }
        this.restoreTimer = null;
    }

    disconnect() {
        clearTimeout(this.restoreTimer);
    }

    copy() {
        navigator.clipboard.writeText(this.textValue).then(() => {
            const button = this.buttonTarget;
            button.textContent = this.copiedLabelValue;
            // Restoring the label captured at connect, not the one on screen: a second
            // click lands while "Copied!" is showing and would otherwise make it stick.
            clearTimeout(this.restoreTimer);
            this.restoreTimer = setTimeout(() => {
                button.textContent = this.originalLabel;
            }, 2000);
        });
    }
}
