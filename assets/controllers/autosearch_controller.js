import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['clearButton', 'field'];
    static values = { ignore: Array };

    connect() {
        this.timeout = null;
        this.#updateClearButton();
        this.#resumeTyping();
    }

    /**
     * Submitting is a full Turbo visit, which replaces the body and destroys the
     * field being typed into — so without this, the rest of a word typed through
     * the 300ms debounce lands nowhere. Restoring focus alone is not enough: the
     * caret returns at position 0, so the continued keystrokes are inserted in
     * front of the term rather than lost, which looks like the app working.
     *
     * A form with no field target (the admin outbox, which filters with selects
     * only) never enters here, so nothing focuses on its behalf.
     */
    #resumeTyping() {
        if (!this.hasFieldTarget || this.fieldTarget.value === '') {
            return;
        }

        this.fieldTarget.focus();
        const end = this.fieldTarget.value.length;
        this.fieldTarget.setSelectionRange(end, end);
    }

    disconnect() {
        clearTimeout(this.timeout);
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
