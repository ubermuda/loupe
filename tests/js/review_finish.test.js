/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReviewFinishController from '../../assets/controllers/review_finish_controller.js';

let application;

beforeEach(async () => {
    document.body.innerHTML = `<button data-controller="review-finish" data-action="click->review-finish#open">Finish review</button>`;
    application = Application.start();
    application.register('review-finish', ReviewFinishController);
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('asks the review block to open its dialog by a window event', () => {
    const heard = vi.fn();
    window.addEventListener('review:finish', heard);

    document.querySelector('button').click();

    expect(heard).toHaveBeenCalledTimes(1);
    window.removeEventListener('review:finish', heard);
});
