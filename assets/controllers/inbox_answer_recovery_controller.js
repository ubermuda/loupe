import { Controller } from '@hotwired/stimulus';
import {
    discardInboxAnswerDraft,
    inboxAnswerDraft,
    useInboxAnswerDraftOwner,
} from '../lib/inbox_answer_drafts.js';

export default class extends Controller {
    static targets = ['text', 'options'];
    static values = { key: String, owner: String, options: Array };

    connect() {
        useInboxAnswerDraftOwner(this.ownerValue);
        this.render();
    }

    render() {
        const draft = inboxAnswerDraft(this.keyValue);
        this.element.hidden = !draft;
        this.textTarget.textContent = draft?.text ?? '';
        this.optionsTarget.replaceChildren();
        for (const index of draft?.options ?? []) {
            const option = document.createElement('li');
            option.textContent = this.optionsValue[Number(index)] ?? index;
            this.optionsTarget.append(option);
        }
        this.optionsTarget.hidden = this.optionsTarget.children.length === 0;
    }

    discard() {
        discardInboxAnswerDraft(this.keyValue);
        this.render();
    }
}
