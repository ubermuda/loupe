const drafts = new Map();
const accepted = new Set();
const submitted = new Map();
let owner;

export function useReplyDraftOwner(value) {
    if (owner !== value) {
        drafts.clear();
        accepted.clear();
        submitted.clear();
        owner = value;
    }
}

export function replyDraft(key) {
    return drafts.get(key);
}

export function rememberReplyDraft(key, body, submissionId) {
    if (
        body.trim() !== '' &&
        submitted.has(submissionId) &&
        submitted.get(submissionId) !== body
    ) {
        submissionId = crypto.randomUUID();
    }
    if (body.trim() === '' || accepted.has(submissionId)) {
        drafts.delete(key);
    } else {
        drafts.set(key, { body, submissionId });
    }
    return submissionId;
}

export function markReplySubmitted(submissionId, body) {
    submitted.set(submissionId, body);
}

export function replyWasAccepted(submissionId) {
    return accepted.has(submissionId);
}

export function acknowledgeReplyDrafts(root) {
    for (const reply of root.querySelectorAll('[data-reply-submission-id]')) {
        accepted.add(reply.dataset.replySubmissionId);
    }
    for (const [key, draft] of drafts) {
        if (accepted.has(draft.submissionId)) drafts.delete(key);
    }
}

document.addEventListener('turbo:before-frame-render', (event) => {
    acknowledgeReplyDrafts(event.detail.newFrame);
});
document.addEventListener('turbo:before-render', (event) => {
    if (event.detail.newBody.hasAttribute('data-reply-draft-owner')) {
        useReplyDraftOwner(event.detail.newBody.dataset.replyDraftOwner);
    }
    acknowledgeReplyDrafts(event.detail.newBody);
});
window.addEventListener('beforeunload', (event) => {
    if (drafts.size > 0) {
        event.preventDefault();
        event.returnValue = '';
    }
});
