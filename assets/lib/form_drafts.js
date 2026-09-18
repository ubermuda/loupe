const drafts = new Map();
const cleared = new Set();
const submitted = new Set();
const submissions = new WeakMap();
let owner;

export function useFormDraftOwner(value) {
    if (owner !== value) {
        drafts.clear();
        cleared.clear();
        submitted.clear();
        owner = value;
    }
}

export function formDraft(key) {
    return drafts.get(key);
}

export function rememberFormDraft(key, draft) {
    if (draft) drafts.set(key, draft);
    else drafts.delete(key);
}

export function acceptFormDraft(key, identity) {
    clearFormDraft(key, identity);
}

export function discardFormDraft(key) {
    const draft = drafts.get(key);
    if (draft) clearFormDraft(key, draft.identity);
}

function clearFormDraft(key, identity) {
    cleared.add(identity);
    if (drafts.get(key)?.identity === identity) drafts.delete(key);
    document.dispatchEvent(
        new CustomEvent('form-draft:cleared', { detail: { key, identity } }),
    );
}

export function submitFormDraft(form, key, identity) {
    submitted.add(identity);
    submissions.set(form, { owner, key, identity });
}

export function formDraftWasSubmitted(identity) {
    return submitted.has(identity);
}

export function formDraftWasCleared(identity) {
    return cleared.has(identity);
}

document.addEventListener('turbo:submit-end', (event) => {
    const submission = submissions.get(event.detail.formSubmission.formElement);
    if (event.detail.success && submission && submission.owner === owner) {
        acceptFormDraft(submission.key, submission.identity);
    }
});

document.addEventListener('turbo:before-render', (event) => {
    if (event.detail.newBody.hasAttribute('data-reply-draft-owner')) {
        useFormDraftOwner(event.detail.newBody.dataset.replyDraftOwner);
    }
});

window.addEventListener('beforeunload', (event) => {
    if (drafts.size > 0) {
        event.preventDefault();
        event.returnValue = '';
    }
});
