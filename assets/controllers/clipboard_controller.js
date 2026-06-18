import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button'];
    static values = {
        text: String,
        label: { type: String, default: 'Copy' },
        copiedLabel: { type: String, default: 'Copied!' },
    };

    copy() {
        navigator.clipboard.writeText(this.textValue).then(() => {
            const btn = this.buttonTarget;
            const original = btn.textContent;
            btn.textContent = this.copiedLabelValue;
            setTimeout(() => {
                btn.textContent = original;
            }, 2000);
        });
    }
}
