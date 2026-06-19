import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['clearButton'];
    static values = { ignore: Array };

    connect() {
        this.timeout = null;
        this.#updateClearButton();
    }

    search() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => {
            this.element.requestSubmit();
        }, 300);
        this.#updateClearButton();
    }

    #hasActiveFilters() {
        const ignored = this.hasIgnoreValue
            ? this.ignoreValue
            : ['sort', 'dir'];
        return Array.from(this.element.elements).some((element) => {
            if (!element.name || ignored.includes(element.name)) return false;
            return element.value !== '';
        });
    }

    #updateClearButton() {
        if (!this.hasClearButtonTarget) return;
        const inactive = !this.#hasActiveFilters();
        this.clearButtonTarget.classList.toggle('opacity-40', inactive);
        this.clearButtonTarget.classList.toggle(
            'pointer-events-none',
            inactive,
        );
    }
}
