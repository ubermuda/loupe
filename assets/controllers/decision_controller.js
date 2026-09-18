import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['form', 'decisionId', 'options', 'expectedOptions'];
    static values = { saveLabel: String };

    connect() {
        if (!this.hasFormTarget) return;
        this.blocks = [...this.element.querySelectorAll('[data-decision-id]')];
        for (const block of this.blocks) {
            block.dataset.savedDecisionIndexes ??= JSON.stringify(
                this.indexes(block),
            );
            block.querySelector('[data-decision-save]')?.remove();
            // An input's value adds no text nodes to the document's annotation offsets.
            const button = document.createElement('input');
            button.type = 'button';
            button.value = this.saveLabelValue;
            button.className = 'lp-btn lp-btn--primary lp-decision__save';
            button.dataset.decisionSave = '';
            button.addEventListener('click', () => this.save(block));
            block.append(button);
            this.updateButton(block);
        }
    }

    indexes(block) {
        return [
            ...block.querySelectorAll('input[data-decision-option]:checked'),
        ].map((input) => Number(input.value));
    }

    select(event) {
        const block = event.target.closest('[data-decision-id]');
        if (block && this.hasFormTarget) this.updateButton(block);
    }

    updateButton(block) {
        block.querySelector('[data-decision-save]').disabled =
            Boolean(this.pending) ||
            JSON.stringify(this.indexes(block)) ===
                block.dataset.savedDecisionIndexes;
    }

    fill(target, indexes) {
        target.replaceChildren(
            ...indexes.map((value, index) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = target.dataset.fieldName + '[' + index + ']';
                input.value = value;
                return input;
            }),
        );
    }

    save(block) {
        if (this.pending) return;
        const indexes = this.indexes(block);
        this.pending = { block, indexes };
        this.decisionIdTarget.value = block.dataset.decisionId;
        this.fill(this.optionsTarget, indexes);
        this.fill(
            this.expectedOptionsTarget,
            JSON.parse(block.dataset.savedDecisionIndexes),
        );
        for (const candidate of this.blocks) {
            for (const input of candidate.querySelectorAll('input'))
                input.disabled = true;
        }
        this.formTarget.requestSubmit();
    }

    saved(event) {
        if (!this.pending) return;
        if (event.detail.success) {
            this.pending.block.dataset.savedDecisionIndexes = JSON.stringify(
                this.pending.indexes,
            );
        }
        this.pending = null;
        for (const block of this.blocks) {
            for (const input of block.querySelectorAll('input'))
                input.disabled = false;
            this.updateButton(block);
        }
    }
}
