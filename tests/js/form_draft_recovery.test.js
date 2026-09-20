/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import FormDraftRecoveryController from '../../assets/controllers/form_draft_recovery_controller.js';
import {
    acceptFormDraft,
    formDraft,
    rememberFormDraft,
    useFormDraftOwner,
} from '../../assets/lib/form_drafts.js';

let application;
let controller;

beforeEach(async () => {
    useFormDraftOwner('owner');
    rememberFormDraft('question', {
        identity: 'draft',
        text: '<b>Unsent text</b>',
        options: ['1'],
    });
    document.body.innerHTML = `<div data-controller="form-draft-recovery" data-form-draft-recovery-owner-value="owner" data-form-draft-recovery-key-value="question" data-form-draft-recovery-options-value='["CSV", "JSON"]' data-action="form-draft:cleared@document->form-draft-recovery#render"><section hidden data-form-draft-recovery-target="panel">
        <p data-form-draft-recovery-target="text"></p>
        <ul data-form-draft-recovery-target="options"></ul>
    </section></div>`;
    application = Application.start();
    application.register('form-draft-recovery', FormDraftRecoveryController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.body.firstElementChild,
        'form-draft-recovery',
    );
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    useFormDraftOwner('');
});

it('shows unsent text and option labels as plain text', () => {
    expect(controller.element.hidden).toBe(false);
    expect(controller.panelTarget.hidden).toBe(false);
    expect(controller.textTarget.textContent).toBe('<b>Unsent text</b>');
    expect(controller.textTarget.children).toHaveLength(0);
    expect(controller.optionsTarget.textContent).toBe('JSON');
});

it('hides the recovery panel after an acknowledgment or explicit discard', () => {
    acceptFormDraft('question', 'draft');
    expect(controller.panelTarget.hidden).toBe(true);
    rememberFormDraft('question', {
        identity: 'later',
        text: 'Later text',
        options: [],
    });
    controller.render();
    expect(controller.panelTarget.hidden).toBe(false);
    controller.discard();
    expect(controller.panelTarget.hidden).toBe(true);
    expect(formDraft('question')).toBeUndefined();
    expect(controller.element.hidden).toBe(true);
});

it('keeps a newer draft visible when an older submission finishes', () => {
    acceptFormDraft('question', 'older');
    expect(controller.panelTarget.hidden).toBe(false);
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
    controller.panelTarget.append(button);
    button.focus();
    controller.discard();
    expect(document.activeElement).toBe(heading);
});

it('returns focus to the ask heading when the item shows no title of its own', () => {
    const ask = document.createElement('section');
    ask.className = 'lp-inbox-ask';
    const heading = document.createElement('h2');
    heading.className = 'lp-inbox-ask__title';
    const request = document.createElement('article');
    request.dataset.inboxItem = '1';
    controller.element.before(ask);
    ask.append(heading, request);
    request.append(controller.element);
    const button = document.createElement('button');
    controller.panelTarget.append(button);
    button.focus();
    controller.discard();
    expect(document.activeElement).toBe(heading);
});

it('returns focus to the document heading after discarding a review draft', () => {
    const heading = document.createElement('h1');
    heading.id = 'review-document-title';
    controller.element.before(heading);
    controller.focusValue = heading.id;
    const button = document.createElement('button');
    controller.panelTarget.append(button);
    button.focus();
    controller.discard();
    expect(document.activeElement).toBe(heading);
});

it('translates verdicts and suppresses the fallback note after recovery and discard', () => {
    const fallback = document.createElement('p');
    fallback.dataset.formDraftRecoveryTarget = 'fallback';
    fallback.textContent = 'Server note';
    controller.element.append(fallback);
    controller.labelsValue = { 'changes-requested': 'Changes requested' };
    rememberFormDraft('question', {
        identity: 'review',
        text: 'Local note',
        options: ['changes-requested'],
    });
    controller.render();
    expect(controller.optionsTarget.textContent).toBe('Changes requested');
    expect(controller.textTarget.textContent).toBe('Local note');
    expect(fallback.hidden).toBe(true);
    controller.discard();
    expect(controller.panelTarget.hidden).toBe(true);
    expect(fallback.hidden).toBe(true);
});

it('keeps a server-rendered note visible when there is no local draft', () => {
    const fallback = document.createElement('p');
    fallback.dataset.formDraftRecoveryTarget = 'fallback';
    fallback.textContent = 'Server note';
    controller.element.append(fallback);
    rememberFormDraft('question', null);
    controller.render();
    expect(controller.panelTarget.hidden).toBe(true);
    expect(fallback.hidden).toBe(false);
    expect(fallback.textContent).toBe('Server note');
    expect(controller.element.hidden).toBe(false);
});
