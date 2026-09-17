import { Controller } from '@hotwired/stimulus';
import {
    acknowledgeReplyDrafts,
    rememberReplyDraft,
    replyDraft,
    replyWasAccepted,
    useReplyDraftOwner,
} from '../lib/reply_drafts.js';

export default class extends Controller {
    static targets = ['error', 'body', 'submission'];
    static values = { owner: String, key: String };

    connect() {
        useReplyDraftOwner(this.ownerValue);
        acknowledgeReplyDrafts(document);
        const draft = replyDraft(this.keyValue);
        if (draft) {
            this.bodyTarget.value = draft.body;
            this.submissionTarget.value = draft.submissionId;
        } else if (replyWasAccepted(this.submissionTarget.value)) {
            this.bodyTarget.value = '';
            this.submissionTarget.value = crypto.randomUUID();
        }
        this.remember();
    }

    remember() {
        rememberReplyDraft(
            this.keyValue,
            this.bodyTarget.value,
            this.submissionTarget.value,
        );
    }

    start() {
        this.remember();
        this.errorTarget.hidden = true;
    }

    failed(event) {
        event.preventDefault();
        this.errorTarget.hidden = false;
    }

    response(event) {
        if (event.detail.fetchResponse.statusCode >= 500) {
            this.failed(event);
        }
    }
}
