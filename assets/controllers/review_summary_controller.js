import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['status'];
    static values = {
        header: String,
        copied: String,
        failed: String,
    };

    async copy() {
        const comments = document.getElementById('review-summary-comments');
        try {
            if (!comments || !navigator.clipboard) {
                throw new Error('Review summary or clipboard unavailable');
            }
            await navigator.clipboard.writeText(
                `${this.headerValue}\n\n${comments.textContent.trim()}`,
            );
            this.statusTarget.textContent = this.copiedValue;
        } catch {
            this.statusTarget.textContent = this.failedValue;
        }
    }
}
