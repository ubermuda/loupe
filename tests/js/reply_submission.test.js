/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import ReplySubmissionController from '../../assets/controllers/reply_submission_controller.js';
import {
    rememberReplyDraft,
    replyDraft,
    useReplyDraftOwner,
} from '../../assets/lib/reply_drafts.js';

let application;
let form;

beforeEach(async () => {
    useReplyDraftOwner('reset');
    application = Application.start();
    application.register('reply-submission', ReplySubmissionController);
    document.body.innerHTML = `<form data-controller="reply-submission" data-reply-submission-owner-value="owner" data-reply-submission-key-value="reply"
        data-action="input->reply-submission#remember turbo:submit-start->reply-submission#start turbo:fetch-request-error->reply-submission#failed turbo:before-fetch-response->reply-submission#response">
        <p data-reply-submission-target="error" hidden>Try again.</p>
        <input type="hidden" data-reply-submission-target="submission" value="submission-one">
        <textarea data-reply-submission-target="body">Keep my draft.</textarea>
    </form>`;
    form = document.querySelector('form');
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    useReplyDraftOwner('finished');
});

it('restores a replaced composer with the same submission identity', async () => {
    const replacement = form.cloneNode(true);
    replacement.querySelector('textarea').value = '';
    replacement.querySelector('input').value = 'new-server-submission';
    form.replaceWith(replacement);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(replacement.querySelector('textarea').value).toBe('Keep my draft.');
    expect(replacement.querySelector('input').value).toBe('submission-one');
});

it('clears only acknowledged drafts before a frame renders', () => {
    const newFrame = document.createElement('turbo-frame');
    newFrame.innerHTML = '<div data-reply-submission-id="unrelated"></div>';
    document.dispatchEvent(
        new CustomEvent('turbo:before-frame-render', { detail: { newFrame } }),
    );
    expect(replyDraft('reply').body).toBe('Keep my draft.');
    newFrame.innerHTML =
        '<div data-reply-submission-id="submission-one"></div>';
    document.dispatchEvent(
        new CustomEvent('turbo:before-frame-render', { detail: { newFrame } }),
    );
    expect(replyDraft('reply')).toBeUndefined();
});

it('does not revive submitted text from a cached form', async () => {
    const newBody = document.createElement('body');
    newBody.innerHTML = '<div data-reply-submission-id="submission-one"></div>';
    document.dispatchEvent(
        new CustomEvent('turbo:before-render', { detail: { newBody } }),
    );
    const replacement = form.cloneNode(true);
    form.replaceWith(replacement);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(replacement.querySelector('textarea').value).toBe('');
    expect(replacement.querySelector('input').value).not.toBe('submission-one');
    expect(replyDraft('reply')).toBeUndefined();
});

it('keeps drafts separated when the signed-in owner changes', () => {
    useReplyDraftOwner('another-owner');
    expect(replyDraft('reply')).toBeUndefined();
});

it('restores a newer draft over a cached submitted form', async () => {
    const newBody = document.createElement('body');
    newBody.innerHTML = '<div data-reply-submission-id="submission-one"></div>';
    document.dispatchEvent(
        new CustomEvent('turbo:before-render', { detail: { newBody } }),
    );
    rememberReplyDraft('reply', 'My next reply.', 'submission-two');
    const replacement = form.cloneNode(true);
    form.replaceWith(replacement);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(replacement.querySelector('textarea').value).toBe('My next reply.');
    expect(replacement.querySelector('input').value).toBe('submission-two');
});

it('clears drafts when navigation reaches a signed-out page', () => {
    const newBody = document.createElement('body');
    newBody.dataset.replyDraftOwner = '';
    document.dispatchEvent(
        new CustomEvent('turbo:before-render', { detail: { newBody } }),
    );
    expect(replyDraft('reply')).toBeUndefined();
});

it('retains drafts when an error page has no owner marker', () => {
    const newBody = document.createElement('body');
    document.dispatchEvent(
        new CustomEvent('turbo:before-render', { detail: { newBody } }),
    );
    expect(replyDraft('reply').body).toBe('Keep my draft.');
});

it('warns before discarding drafts on a full reload and stops after clearing', () => {
    const leave = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leave);
    expect(leave.defaultPrevented).toBe(true);
    form.querySelector('textarea').value = '';
    form.dispatchEvent(new Event('input', { bubbles: true }));
    const leaveClean = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leaveClean);
    expect(leaveClean.defaultPrevented).toBe(false);
});

it('keeps the draft and reports a network failure until retry', () => {
    const failed = new CustomEvent('turbo:fetch-request-error', {
        bubbles: true,
        cancelable: true,
    });
    form.dispatchEvent(failed);
    expect(failed.defaultPrevented).toBe(true);
    expect(form.querySelector('p').hidden).toBe(false);
    expect(form.querySelector('textarea').value).toBe('Keep my draft.');
    form.dispatchEvent(
        new CustomEvent('turbo:submit-start', { bubbles: true }),
    );
    expect(form.querySelector('p').hidden).toBe(true);
    expect(form.querySelector('textarea').value).toBe('Keep my draft.');
});

it.each([500, 503])(
    'preserves the composer instead of rendering an HTTP %s response',
    (statusCode) => {
        const response = new CustomEvent('turbo:before-fetch-response', {
            bubbles: true,
            cancelable: true,
            detail: { fetchResponse: { statusCode } },
        });
        form.dispatchEvent(response);
        expect(response.defaultPrevented).toBe(true);
        expect(form.querySelector('p').hidden).toBe(false);
        expect(form.querySelector('textarea').value).toBe('Keep my draft.');
    },
);

it.each([200, 422])(
    'allows Turbo to render an HTTP %s response',
    (statusCode) => {
        const response = new CustomEvent('turbo:before-fetch-response', {
            bubbles: true,
            cancelable: true,
            detail: { fetchResponse: { statusCode } },
        });
        form.dispatchEvent(response);
        expect(response.defaultPrevented).toBe(false);
        expect(form.querySelector('p').hidden).toBe(true);
    },
);
