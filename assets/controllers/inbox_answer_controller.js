import { Controller } from '@hotwired/stimulus';
import {
    acceptInboxAnswerDraft,
    inboxAnswerDraft,
    inboxAnswerWasAccepted,
    inboxAnswerWasSubmitted,
    rememberInboxAnswerDraft,
    submitInboxAnswerDraft,
    useInboxAnswerDraftOwner,
} from '../lib/inbox_answer_drafts.js';

export default class extends Controller {
    static targets = ['option', 'selectedOptions', 'text'];
    static values = { owner: String, key: String };

    connect() {
        useInboxAnswerDraftOwner(this.ownerValue);
        this.baseline = JSON.stringify(this.fields());
        this.identity =
            this.element.dataset.inboxAnswerDraftIdentity ??
            crypto.randomUUID();
        const draft = inboxAnswerDraft(this.keyValue);
        if (draft) {
            this.baseline = draft.baseline;
            this.identity = draft.identity;
            this.optionTargets.forEach((option) => {
                option.checked = draft.options.includes(option.value);
            });
            if (this.hasTextTarget) this.textTarget.value = draft.text;
        } else if (inboxAnswerWasAccepted(this.identity)) {
            this.optionTargets.forEach((option) => {
                option.checked = option.defaultChecked;
            });
            if (this.hasTextTarget)
                this.textTarget.value = this.textTarget.defaultValue;
            this.baseline = JSON.stringify(this.fields());
            this.identity = crypto.randomUUID();
        }
        this.element.dataset.inboxAnswerDraftIdentity = this.identity;
        this.sync();
    }

    select() {
        this.sync();
        this.remember();
    }

    sync() {
        if (!this.hasSelectedOptionsTarget) {
            return;
        }

        this.selectedOptionsTarget.value = this.optionTargets
            .filter((option) => option.checked)
            .map((option) => option.value)
            .join(',');
    }

    remember() {
        if (inboxAnswerWasSubmitted(this.identity)) {
            this.identity = crypto.randomUUID();
            this.element.dataset.inboxAnswerDraftIdentity = this.identity;
        }
        const fields = this.fields();
        rememberInboxAnswerDraft(
            this.keyValue,
            JSON.stringify(fields) === this.baseline
                ? null
                : {
                      identity: this.identity,
                      baseline: this.baseline,
                      ...fields,
                  },
        );
    }

    fields() {
        return {
            options: this.optionTargets
                .filter((option) => option.checked)
                .map((option) => option.value),
            text: this.hasTextTarget ? this.textTarget.value : '',
        };
    }

    start() {
        this.submitted = this.identity;
        submitInboxAnswerDraft(this.element, this.keyValue, this.identity);
    }

    accepted(event) {
        if (
            event.detail.key !== this.keyValue ||
            event.detail.identity !== this.identity
        )
            return;
        this.optionTargets.forEach((option) => {
            option.checked = option.defaultChecked;
        });
        if (this.hasTextTarget)
            this.textTarget.value = this.textTarget.defaultValue;
        this.baseline = JSON.stringify(this.fields());
        this.identity = crypto.randomUUID();
        this.element.dataset.inboxAnswerDraftIdentity = this.identity;
        this.sync();
    }

    response(event) {
        if (event.detail.fetchResponse.succeeded && this.submitted) {
            acceptInboxAnswerDraft(this.keyValue, this.submitted);
        }
    }

    // A radio cannot be unticked by a click, so a text-only answer needs this.
    clear() {
        this.optionTargets.forEach((option) => {
            option.checked = false;
        });
        this.select();
    }
}
