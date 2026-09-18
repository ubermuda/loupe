import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'trigger',
        'dialog',
        'form',
        'query',
        'frame',
        'loading',
        'error',
        'close',
    ];

    disconnect() {
        clearTimeout(this.timer);
    }

    shortcut(event) {
        if (event.key === 'Escape' && this.dialogTarget.open) {
            event.preventDefault();
            this.closeTarget.click();
            return;
        }
        if (
            !(event.ctrlKey || event.metaKey) ||
            event.altKey ||
            event.key.toLowerCase() !== 'k'
        )
            return;
        const openDialog = document.querySelector('dialog[open]');
        if (openDialog && openDialog !== this.dialogTarget) return;
        event.preventDefault();
        if (this.dialogTarget.open) {
            this.queryTarget.focus();
        } else {
            this.triggerTarget.click();
        }
    }

    open() {
        this.queryTarget.focus();
        this.formTarget.requestSubmit();
    }

    search() {
        clearTimeout(this.timer);
        this.loading();
        this.timer = setTimeout(() => this.formTarget.requestSubmit(), 300);
    }

    submitted() {
        clearTimeout(this.timer);
    }

    loading() {
        this.loadingTarget.hidden = false;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = true;
    }

    received(event) {
        const response = event.detail.fetchResponse;
        if (!response.succeeded || !response.isHTML || response.redirected)
            this.failed(event);
    }

    loaded() {
        const query = this.frameTarget.querySelector('[data-search-query]')
            ?.dataset.searchQuery;
        const currentQuery = this.queryTarget.value.replace(
            /^[\p{Z}\p{Cc}\p{Cf}]+|[\p{Z}\p{Cc}\p{Cf}]+$/gu,
            '',
        );
        if (query !== currentQuery) return;
        this.loadingTarget.hidden = true;
        this.errorTarget.hidden = true;
        this.frameTarget.hidden = false;
    }

    failed(event) {
        event.preventDefault();
        this.loadingTarget.hidden = true;
        this.errorTarget.hidden = false;
        this.frameTarget.hidden = true;
    }
}
