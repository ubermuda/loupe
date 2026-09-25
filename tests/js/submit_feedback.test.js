/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import SubmitFeedbackController from '../../assets/controllers/submit_feedback_controller.js';

let application;

beforeEach(() => {
    vi.useFakeTimers();
    application = Application.start();
    application.register('submit-feedback', SubmitFeedbackController);
});

afterEach(() => {
    document.body.replaceChildren();
    application.stop();
    vi.useRealTimers();
});

async function mount(saved) {
    document.body.innerHTML = `<form data-controller="submit-feedback" data-submit-feedback-saved-label-value="Saved"${saved ? ' data-submit-feedback-saved-value="true"' : ''}>
        <button type="submit" data-submit-feedback-target="button">Save card</button>
    </form>`;
    await vi.advanceTimersByTimeAsync(0);

    return document.querySelector('button');
}

it('shows the saved label on a form the server marks saved, then the normal label', async () => {
    const button = await mount(true);
    expect(button.textContent).toBe('Saved');
    await vi.advanceTimersByTimeAsync(2999);
    expect(button.textContent).toBe('Saved');
    await vi.advanceTimersByTimeAsync(1);
    expect(button.textContent).toBe('Save card');
});

it('leaves the label of a form that is not marked saved', async () => {
    const button = await mount(false);
    expect(button.textContent.trim()).toBe('Save card');
});

it('stops the timer when the form leaves the page', async () => {
    const button = await mount(true);
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(3000);
    expect(button.textContent).toBe('Saved');
});
