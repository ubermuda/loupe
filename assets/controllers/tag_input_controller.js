import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static targets = ['input', 'field', 'dropdown', 'suggestions'];

    #tags = [];
    #allTags = [];
    #clickOutside = null;

    connect() {
        const el = document.getElementById('tag-input-data');
        this.#allTags = el ? JSON.parse(el.textContent) : [];
        this.#tags = this.#parseField();
        this.#renderPills();
        this.#clickOutside = (e) => {
            if (!this.element.contains(e.target)) this.close();
        };
        document.addEventListener('click', this.#clickOutside);
    }

    disconnect() {
        document.removeEventListener('click', this.#clickOutside);
    }

    open() {
        if (this.#filter() > 0) {
            this.dropdownTarget.classList.remove('hidden');
        }
    }

    close() {
        this.dropdownTarget.classList.add('hidden');
    }

    filter() {
        if (this.#filter() > 0) {
            this.dropdownTarget.classList.remove('hidden');
        } else {
            this.close();
        }
    }

    addFromDropdown(event) {
        const tag = event.currentTarget.dataset.tag;
        this.#addTag(tag);
        this.inputTarget.value = '';
        this.close();
        this.inputTarget.focus();
    }

    addFromInput(event) {
        if (event.key === 'Escape') {
            this.close();
            this.inputTarget.blur();
            return;
        }
        if (event.key !== 'Enter' && event.key !== ',') return;
        event.preventDefault();
        const value = this.inputTarget.value.trim();
        if (value) this.#addTag(value);
        this.inputTarget.value = '';
        this.close();
    }

    removeTag(event) {
        const tag = event.currentTarget.dataset.tag;
        this.#tags = this.#tags.filter((t) => t !== tag);
        this.#syncField();
        this.#renderPills();
    }

    #addTag(tag) {
        tag = tag.trim();
        if (!tag || this.#tags.includes(tag)) return;
        this.#tags = [...this.#tags, tag];
        this.#syncField();
        this.#renderPills();
    }

    #filter() {
        const q = this.inputTarget.value.toLowerCase();
        const filtered = this.#allTags.filter(
            (t) => !this.#tags.includes(t) && t.toLowerCase().includes(q),
        );
        this.suggestionsTarget.innerHTML = filtered
            .map(
                (t) =>
                    `<button type="button" class="w-full text-left px-3 py-1.5 text-sm hover:bg-slate-100 rounded" data-action="click->tag-input#addFromDropdown" data-tag="${this.#escAttr(t)}">${this.#escHtml(t)}</button>`,
            )
            .join('');
        return filtered.length;
    }

    #renderPills() {
        const container = this.element.querySelector(
            '[data-tag-input-pill-container]',
        );
        if (!container) return;
        container.innerHTML = this.#tags
            .map(
                (t) =>
                    `<span class="admin-badge admin-badge-neutral flex items-center gap-1">
                ${this.#escHtml(t)}
                <button type="button" class="ml-0.5 text-slate-400 hover:text-slate-600" aria-label="Remove ${this.#escAttr(t)}" data-action="click->tag-input#removeTag" data-tag="${this.#escAttr(t)}">×</button>
            </span>`,
            )
            .join('');
    }

    #parseField() {
        try {
            const v = this.fieldTarget.value;
            return v ? JSON.parse(v) : [];
        } catch {
            return [];
        }
    }

    #syncField() {
        this.fieldTarget.value = JSON.stringify(this.#tags);
    }

    #escHtml(s) {
        return s
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Escaping only the quote is not enough: these strings are assigned through
    // innerHTML, so a stored tag containing `&quot;` would decode back into a
    // real quote and break out of the attribute. Escape the ampersand first
    // (via #escHtml, which does & before < and >), then the quote.
    #escAttr(s) {
        return this.#escHtml(s).replace(/"/g, '&quot;');
    }
}
