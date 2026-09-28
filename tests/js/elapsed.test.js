/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ElapsedController, {
    TICK_MILLISECONDS,
} from '../../assets/controllers/elapsed_controller.js';

const NOW = new Date('2026-09-28T12:00:00Z');

let application;

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
    application = Application.start();
    application.register('elapsed', ElapsedController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
});

async function mount(secondsAgo, { datetime = null, text = 'rendered' } = {}) {
    const stamp =
        datetime ?? new Date(NOW.getTime() - secondsAgo * 1000).toISOString();
    document.body.innerHTML = `<time datetime="${stamp}" data-controller="elapsed"
        data-elapsed-minutes-value="%count% min"
        data-elapsed-hours-value="%count% h"
        data-elapsed-days-value="%count% d">${text}</time>`;
    await vi.advanceTimersByTimeAsync(0);
    return document.querySelector('time');
}

it.each([
    [0, '0 min'],
    [59, '0 min'],
    [60, '1 min'],
    [12 * 60, '12 min'],
    [59 * 60 + 59, '59 min'],
    [60 * 60, '1 h'],
    [3 * 60 * 60, '3 h'],
    [1439 * 60 + 59, '23 h'],
    [1440 * 60, '1 d'],
    [3 * 1440 * 60, '3 d'],
])('shows %i seconds ago as %s', async (secondsAgo, expected) => {
    const element = await mount(secondsAgo);
    expect(element.textContent).toBe(expected);
});

it('shows a datetime in the future as 0 min', async () => {
    const element = await mount(-120);
    expect(element.textContent).toBe('0 min');
});

it('leaves the text alone when the datetime does not parse', async () => {
    const element = await mount(0, { datetime: 'not a date', text: '12 min' });
    expect(element.textContent).toBe('12 min');
});

it('rewrites the text every 30 seconds', async () => {
    const element = await mount(59 * 60 + 20);
    expect(element.textContent).toBe('59 min');
    await vi.advanceTimersByTimeAsync(TICK_MILLISECONDS - 1);
    expect(element.textContent).toBe('59 min');
    await vi.advanceTimersByTimeAsync(1);
    expect(element.textContent).toBe('59 min');
    await vi.advanceTimersByTimeAsync(TICK_MILLISECONDS);
    expect(element.textContent).toBe('1 h');
});

it('leaves no timer after disconnect', async () => {
    await mount(0);
    expect(vi.getTimerCount()).toBe(1);
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(vi.getTimerCount()).toBe(0);
});
