const drafts = new Map();
const accepted = new Set();
const submitted = new Set();
const submissions = new WeakMap();
let owner;

export function useInboxAnswerDraftOwner(value) {
    if (owner !== value) {
        drafts.clear();
        accepted.clear();
        submitted.clear();
        owner = value;
    }
}

export function inboxAnswerDraft(key) {
    return drafts.get(key);
}

export function rememberInboxAnswerDraft(key, draft) {
    if (draft) drafts.set(key, draft);
    else drafts.delete(key);
}

export function acceptInboxAnswerDraft(key, identity) {
    accepted.add(identity);
    if (drafts.get(key)?.identity === identity) drafts.delete(key);
    document.dispatchEvent(
        new CustomEvent('inbox-answer:accepted', { detail: { key, identity } }),
    );
}

export function submitInboxAnswerDraft(form, key, identity) {
    submitted.add(identity);
    submissions.set(form, { owner, key, identity });
}

export function inboxAnswerWasSubmitted(identity) {
    return submitted.has(identity);
}

export function inboxAnswerWasAccepted(identity) {
    return accepted.has(identity);
}

document.addEventListener('turbo:submit-end', (event) => {
    const submission = submissions.get(event.detail.formSubmission.formElement);
    if (event.detail.success && submission && submission.owner === owner) {
        acceptInboxAnswerDraft(submission.key, submission.identity);
    }
});

document.addEventListener('turbo:before-render', (event) => {
    if (event.detail.newBody.hasAttribute('data-reply-draft-owner')) {
        useInboxAnswerDraftOwner(event.detail.newBody.dataset.replyDraftOwner);
    }
});

window.addEventListener('beforeunload', (event) => {
    if (drafts.size > 0) {
        event.preventDefault();
        event.returnValue = '';
    }
});
