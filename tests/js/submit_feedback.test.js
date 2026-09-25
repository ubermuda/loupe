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
        <p role="status" data-submit-feedback-target="status"></p>
    </form>`;
    await vi.advanceTimersByTimeAsync(0);

    return document.querySelector('button');
}

function status() {
    return document.querySelector('[role="status"]').textContent;
}

it('announces the save in the status region, then empties it', async () => {
    await mount(true);
    expect(status()).toBe('');
    await vi.advanceTimersByTimeAsync(100);
    expect(status()).toBe('Saved');
    await vi.advanceTimersByTimeAsync(2900);
    expect(status()).toBe('');
});

it('announces nothing for a form that is not marked saved', async () => {
    await mount(false);
    await vi.advanceTimersByTimeAsync(3000);
    expect(status()).toBe('');
});

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
