/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import ReplySubmissionController from '../../assets/controllers/reply_submission_controller.js';

let application;
let form;

beforeEach(async () => {
    application = Application.start();
    application.register('reply-submission', ReplySubmissionController);
    document.body.innerHTML = `<form data-controller="reply-submission"
        data-action="turbo:submit-start->reply-submission#start turbo:fetch-request-error->reply-submission#failed turbo:before-fetch-response->reply-submission#response">
        <p data-reply-submission-target="error" hidden>Try again.</p>
        <textarea>Keep my draft.</textarea>
    </form>`;
    form = document.querySelector('form');
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
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
