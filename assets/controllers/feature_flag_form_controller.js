import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'typeSelect',
        'boolField',
        'intField',
        'selectField',
        'optionsField',
    ];

    connect() {
        this.updateType();
        this._wireOptionsSync();
    }

    updateType() {
        const type = this.typeSelectTarget.value;
        this.boolFieldTarget.hidden = type !== 'bool';
        this.intFieldTarget.hidden = type !== 'int';
        if (this.hasSelectFieldTarget)
            this.selectFieldTarget.hidden = type !== 'select';
        if (this.hasOptionsFieldTarget)
            this.optionsFieldTarget.hidden = type !== 'select';
    }

    // Keep the selectValue <select> in sync with whatever the user types in the
    // options <textarea>. Without this, the dropdown is empty on initial create
    // (server only repopulates choices on submit), so admins couldn't pick a value
    // until after a round-trip.
    _wireOptionsSync() {
        if (!this.hasOptionsFieldTarget || !this.hasSelectFieldTarget) return;
        const textarea = this.optionsFieldTarget.querySelector('textarea');
        const select = this.selectFieldTarget.querySelector('select');
        if (!textarea || !select) return;

        const sync = () => {
            const lines = textarea.value
                .split(/\r\n|\r|\n/)
                .map((line) => line.trim())
                .filter((line) => line !== '');
            const previousValue = select.value;
            select.innerHTML = '';
            for (const line of lines) {
                const option = document.createElement('option');
                option.value = line;
                option.textContent = line;
                select.appendChild(option);
            }
            if (lines.includes(previousValue)) select.value = previousValue;
        };

        textarea.addEventListener('input', sync);
        sync();
    }
}
