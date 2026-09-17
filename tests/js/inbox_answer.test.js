/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import InboxAnswerController from '../../assets/controllers/inbox_answer_controller.js';
import {
    discardInboxAnswerDraft,
    inboxAnswerDraft,
    useInboxAnswerDraftOwner,
} from '../../assets/lib/inbox_answer_drafts.js';

let application;
let owner;

async function mount(markup = '') {
    if (markup instanceof Element) document.body.replaceChildren(markup);
    else
        document.body.innerHTML =
            markup ||
            `<form data-controller="inbox-answer" data-action="inbox-answer:cleared@document->inbox-answer#cleared" data-inbox-answer-owner-value="${owner}" data-inbox-answer-key-value="question">
        <input type="checkbox" value="0" data-inbox-answer-target="option">
        <input type="checkbox" value="1" data-inbox-answer-target="option">
        <input type="hidden" data-inbox-answer-target="selectedOptions">
        <textarea data-inbox-answer-target="text"></textarea>
    </form>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
    return application.getControllerForElementAndIdentifier(
        document.querySelector('form'),
        'inbox-answer',
    );
}

beforeEach(() => {
    owner = crypto.randomUUID();
    application = Application.start();
    application.register('inbox-answer', InboxAnswerController);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    useInboxAnswerDraftOwner('');
});

it('restores text and selected options after the form is replaced', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Keep this answer';
    controller.optionTargets[1].checked = true;
    controller.select();
    controller = await mount();
    expect(controller.textTarget.value).toBe('Keep this answer');
    expect(controller.optionTargets.map((option) => option.checked)).toEqual([
        false,
        true,
    ]);
    expect(controller.selectedOptionsTarget.value).toBe('1');
});

it('keeps a newer draft when an earlier answer succeeds', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Submitted answer';
    controller.remember();
    controller.start();
    controller.textTarget.value = 'A newer answer';
    controller.remember();
    controller.response({ detail: { fetchResponse: { succeeded: true } } });
    controller = await mount();
    expect(controller.textTarget.value).toBe('A newer answer');
});

it('clears accepted drafts from fresh and cached forms', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Submitted answer';
    controller.remember();
    const cached = document.querySelector('form').cloneNode(true);
    expect(cached.querySelector('textarea').value).toBe('Submitted answer');
    controller.start();
    controller.response({ detail: { fetchResponse: { succeeded: true } } });
    expect(inboxAnswerDraft('question')).toBeUndefined();
    controller = await mount();
    expect(controller.textTarget.value).toBe('');
    controller = await mount(cached);
    expect(controller.textTarget.value).toBe('');
});

it('retains rejected answers and separates accounts', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Rejected answer';
    controller.remember();
    controller.start();
    controller.response({ detail: { fetchResponse: { succeeded: false } } });
    controller = await mount();
    expect(controller.textTarget.value).toBe('Rejected answer');
    owner = crypto.randomUUID();
    controller = await mount();
    expect(controller.textTarget.value).toBe('');
});

it('removes a draft when the fields return to their initial values', async () => {
    const controller = await mount();
    controller.textTarget.value = 'Changed';
    controller.remember();
    controller.textTarget.value = '';
    controller.remember();
    expect(inboxAnswerDraft('question')).toBeUndefined();
});

it('preserves edits made after reopening a pending submission', async () => {
    const original = await mount();
    original.textTarget.value = 'Submitted answer';
    original.remember();
    original.start();
    const reopened = await mount();
    reopened.textTarget.value = 'Edited after reopening';
    reopened.remember();
    document.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: {
                formSubmission: { formElement: original.element },
                success: true,
            },
        }),
    );
    const restored = await mount();
    expect(restored.textTarget.value).toBe('Edited after reopening');
});

it('acknowledges a submission after its original form disconnects', async () => {
    const original = await mount();
    original.textTarget.value = 'Submitted answer';
    original.remember();
    original.start();
    const reopened = await mount();
    document.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: {
                formSubmission: { formElement: original.element },
                success: true,
            },
        }),
    );
    expect(reopened.textTarget.value).toBe('');
    expect(inboxAnswerDraft('question')).toBeUndefined();
});

it('ignores unrelated successful forms before an owner is known', () => {
    useInboxAnswerDraftOwner(undefined);
    document.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: {
                formSubmission: { formElement: document.createElement('form') },
                success: true,
            },
        }),
    );
    expect(inboxAnswerDraft('question')).toBeUndefined();
});

it('does not restore a discarded draft from a cached form', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Discard this text';
    controller.remember();
    const cached = controller.element.cloneNode(true);
    discardInboxAnswerDraft('question');
    controller = await mount(cached);
    expect(controller.textTarget.value).toBe('');
    expect(inboxAnswerDraft('question')).toBeUndefined();
});
