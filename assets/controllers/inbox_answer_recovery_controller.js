import { Controller } from '@hotwired/stimulus';
import {
    discardInboxAnswerDraft,
    inboxAnswerDraft,
    useInboxAnswerDraftOwner,
} from '../lib/inbox_answer_drafts.js';

export default class extends Controller {
    static targets = ['text', 'options', 'panel', 'fallback'];
    static values = {
        key: String,
        owner: String,
        options: Array,
        labels: Object,
    };

    connect() {
        useInboxAnswerDraftOwner(this.ownerValue);
        this.render();
    }

    render() {
        const draft = inboxAnswerDraft(this.keyValue);
        this.panelTarget.hidden = !draft;
        if (draft && this.hasFallbackTarget) this.fallbackTarget.hidden = true;
        this.element.hidden =
            !draft && (!this.hasFallbackTarget || this.fallbackTarget.hidden);
        this.textTarget.textContent = draft?.text ?? '';
        this.optionsTarget.replaceChildren();
        for (const index of draft?.options ?? []) {
            const option = document.createElement('li');
            option.textContent =
                this.labelsValue[index] ??
                this.optionsValue[Number(index)] ??
                index;
            this.optionsTarget.append(option);
        }
        this.optionsTarget.hidden = this.optionsTarget.children.length === 0;
    }

    discard() {
        const containsFocus = this.element.contains(document.activeElement);
        const heading = this.element
            .closest('[data-inbox-item]')
            ?.querySelector('.lp-inbox-item__title');
        discardInboxAnswerDraft(this.keyValue);
        this.render();
        if (containsFocus && heading) {
            heading.setAttribute('tabindex', '-1');
            heading.focus();
        }
    }
}
