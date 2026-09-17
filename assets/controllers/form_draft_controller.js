import { Controller } from '@hotwired/stimulus';
import {
    acceptFormDraft,
    discardFormDraft,
    formDraft,
    formDraftWasCleared,
    formDraftWasSubmitted,
    rememberFormDraft,
    submitFormDraft,
    useFormDraftOwner,
} from '../lib/form_drafts.js';

export default class extends Controller {
    static targets = [
        'option',
        'selectedOptions',
        'text',
        'field',
        'guard',
        'stale',
        'notice',
    ];
    static values = { owner: String, key: String };

    connect() {
        useFormDraftOwner(this.ownerValue);
        this.freshGuards = JSON.parse(
            this.element.dataset.formDraftFreshGuards ??
                JSON.stringify(this.guards()),
        );
        this.element.dataset.formDraftFreshGuards = JSON.stringify(
            this.freshGuards,
        );
        this.baseline = JSON.stringify(this.fields());
        this.identity =
            this.element.dataset.formDraftIdentity ?? crypto.randomUUID();
        const draft = formDraft(this.keyValue);
        if (draft) {
            this.baseline = draft.baseline;
            this.identity = draft.identity;
            this.optionTargets.forEach((option) => {
                option.checked = draft.options.includes(option.value);
            });
            if (this.hasTextTarget) this.textTarget.value = draft.text;
            for (const field of this.fieldTargets) {
                const saved = draft.fields?.find(
                    ([name]) => name === field.name,
                );
                if (saved) field.value = saved[1];
            }
            this.restoreGuards(draft.guards ?? this.freshGuards);
            this.element.closest('details')?.setAttribute('open', '');
        } else if (formDraftWasCleared(this.identity)) {
            this.optionTargets.forEach((option) => {
                option.checked = option.defaultChecked;
            });
            if (this.hasTextTarget)
                this.textTarget.value = this.textTarget.defaultValue;
            for (const field of this.fieldTargets)
                field.value = field.defaultValue;
            this.restoreGuards(this.freshGuards);
            this.baseline = JSON.stringify(this.fields());
            this.identity = crypto.randomUUID();
        }
        this.element.dataset.formDraftIdentity = this.identity;
        this.sync();
    }

    select() {
        this.sync();
        this.remember();
    }

    sync() {
        if (this.hasNoticeTarget)
            this.noticeTarget.hidden = !formDraft(this.keyValue);
        if (this.hasStaleTarget) {
            this.staleTarget.hidden =
                JSON.stringify(this.guards()) ===
                JSON.stringify(this.freshGuards);
        }
        if (!this.hasSelectedOptionsTarget) {
            return;
        }

        this.selectedOptionsTarget.value = this.optionTargets
            .filter((option) => option.checked)
            .map((option) => option.value)
            .join(',');
    }

    remember() {
        if (formDraftWasSubmitted(this.identity)) {
            this.identity = crypto.randomUUID();
            this.element.dataset.formDraftIdentity = this.identity;
        }
        const fields = this.fields();
        rememberFormDraft(
            this.keyValue,
            JSON.stringify(fields) === this.baseline
                ? null
                : {
                      identity: this.identity,
                      baseline: this.baseline,
                      ...fields,
                  },
        );
        this.sync();
    }

    fields() {
        return {
            options: this.optionTargets
                .filter((option) => option.checked)
                .map((option) => option.value),
            text: this.hasTextTarget ? this.textTarget.value : '',
            guards: this.guards(),
            fields: this.fieldTargets.map((field) => [field.name, field.value]),
        };
    }

    guards() {
        return this.guardTargets.map((guard) => [guard.name, guard.value]);
    }

    restoreGuards(values) {
        for (const guard of this.guardTargets) {
            const saved = values.find(([name]) => name === guard.name);
            if (saved) guard.value = saved[1];
        }
    }

    discard() {
        discardFormDraft(this.keyValue);
        (this.fieldTargets[0] ?? this.textTarget).focus();
    }

    start() {
        this.submitted = this.identity;
        submitFormDraft(this.element, this.keyValue, this.identity);
    }

    cleared(event) {
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
        for (const field of this.fieldTargets) field.value = field.defaultValue;
        this.restoreGuards(this.freshGuards);
        this.baseline = JSON.stringify(this.fields());
        this.identity = crypto.randomUUID();
        this.element.dataset.formDraftIdentity = this.identity;
        this.sync();
    }

    response(event) {
        if (event.detail.fetchResponse.succeeded && this.submitted) {
            acceptFormDraft(this.keyValue, this.submitted);
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
