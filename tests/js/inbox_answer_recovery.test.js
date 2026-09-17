/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import InboxAnswerRecoveryController from '../../assets/controllers/inbox_answer_recovery_controller.js';
import {
    acceptInboxAnswerDraft,
    inboxAnswerDraft,
    rememberInboxAnswerDraft,
    useInboxAnswerDraftOwner,
} from '../../assets/lib/inbox_answer_drafts.js';

let application;
let controller;

beforeEach(async () => {
    useInboxAnswerDraftOwner('owner');
    rememberInboxAnswerDraft('question', {
        identity: 'draft',
        text: '<b>Unsent text</b>',
        options: ['1'],
    });
    document.body.innerHTML = `<section hidden data-controller="inbox-answer-recovery" data-inbox-answer-recovery-owner-value="owner" data-inbox-answer-recovery-key-value="question" data-inbox-answer-recovery-options-value='["CSV", "JSON"]' data-action="inbox-answer:cleared@document->inbox-answer-recovery#render">
        <p data-inbox-answer-recovery-target="text"></p>
        <ul data-inbox-answer-recovery-target="options"></ul>
    </section>`;
    application = Application.start();
    application.register(
        'inbox-answer-recovery',
        InboxAnswerRecoveryController,
    );
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.body.firstElementChild,
        'inbox-answer-recovery',
    );
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    useInboxAnswerDraftOwner('');
});

it('shows unsent text and option labels as plain text', () => {
    expect(controller.element.hidden).toBe(false);
    expect(controller.textTarget.textContent).toBe('<b>Unsent text</b>');
    expect(controller.textTarget.children).toHaveLength(0);
    expect(controller.optionsTarget.textContent).toBe('JSON');
});

it('hides the recovery panel after an acknowledgment or explicit discard', () => {
    acceptInboxAnswerDraft('question', 'draft');
    expect(controller.element.hidden).toBe(true);
    rememberInboxAnswerDraft('question', {
        identity: 'later',
        text: 'Later text',
        options: [],
    });
    controller.render();
    expect(controller.element.hidden).toBe(false);
    controller.discard();
    expect(controller.element.hidden).toBe(true);
    expect(inboxAnswerDraft('question')).toBeUndefined();
});

it('keeps a newer draft visible when an older submission finishes', () => {
    acceptInboxAnswerDraft('question', 'older');
    expect(controller.element.hidden).toBe(false);
    expect(controller.textTarget.textContent).toBe('<b>Unsent text</b>');
});

it('returns focus to the request heading after discarding a draft', () => {
    const request = document.createElement('article');
    request.dataset.inboxItem = '1';
    const heading = document.createElement('h3');
    heading.className = 'lp-inbox-item__title';
    heading.textContent = 'Request';
    controller.element.before(request);
    request.append(heading, controller.element);
    const button = document.createElement('button');
    controller.element.append(button);
    button.focus();
    controller.discard();
    expect(document.activeElement).toBe(heading);
});
