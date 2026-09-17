import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['output', 'status'];
    static values = { copied: String, failed: String };

    async copy() {
        this.statusTarget.textContent = '';
        try {
            await navigator.clipboard.writeText(this.outputTarget.textContent);
            this.statusTarget.textContent = this.copiedValue;
        } catch {
            this.statusTarget.textContent = this.failedValue;
        }
    }
}
