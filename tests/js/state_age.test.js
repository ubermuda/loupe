/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import StateAgeController, {
    ageForm,
} from '../../assets/controllers/state_age_controller.js';

let application;

const texts = {
    ago: {
        now: 'just now',
        minute: '1 minute ago',
        minutes: '%count% minutes ago',
        hour: '1 hour ago',
        hours: '%count% hours ago',
        day: '1 day ago',
        days: '%count% days ago',
    },
    duration: {
        now: 'under a minute',
        minute: '1 min',
        minutes: '%count% min',
        hour: '1 h',
        hours: '%count% h',
        day: '1 d',
        days: '%count% d',
    },
};

const SERVER_NOW = 1_800_000_000;

beforeEach(() => {
    vi.useFakeTimers();
    // The reader's clock runs an hour behind the server.
    vi.setSystemTime((SERVER_NOW - 3600) * 1000);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application?.stop();
    vi.useRealTimers();
});

const mount = async (mode, since) => {
    document.body.innerHTML = `
        <script type="application/json" data-state-age-texts>${JSON.stringify(texts)}</script>
        <span id="age" data-controller="state-age" data-state-age-since-value="${since}" data-state-age-now-value="${SERVER_NOW}" data-state-age-mode-value="${mode}">server text</span>`;
    application = Application.start();
    application.register('state-age', StateAgeController);
    await vi.advanceTimersByTimeAsync(0);
};

const age = () => document.querySelector('#age').textContent;

it('counts from the server clock, whatever the clock of the reader', async () => {
    await mount('ago', SERVER_NOW - 2 * 3600);

    expect(age()).toBe('2 hours ago');
});

it('moves the age on while the page stays open', async () => {
    await mount('duration', SERVER_NOW - 59 * 60);
    expect(age()).toBe('59 min');

    await vi.advanceTimersByTimeAsync(60_000);

    expect(age()).toBe('1 h');
});

it('keeps its clock offset when a restored page connects again', async () => {
    await mount('ago', SERVER_NOW - 60 * 60);
    const element = document.querySelector('#age');
    const snapshot = element.outerHTML;
    element.remove();
    await vi.advanceTimersByTimeAsync(0);

    vi.setSystemTime((SERVER_NOW - 3600 + 2 * 3600) * 1000);
    document.body.insertAdjacentHTML('beforeend', snapshot);
    await vi.advanceTimersByTimeAsync(0);

    expect(age()).toBe('3 hours ago');
});

it('keeps the server text when the page carries no texts', async () => {
    document.body.innerHTML = `<span id="age" data-controller="state-age" data-state-age-since-value="${SERVER_NOW}" data-state-age-now-value="${SERVER_NOW}" data-state-age-mode-value="ago">server text</span>`;
    application = Application.start();
    application.register('state-age', StateAgeController);
    await vi.advanceTimersByTimeAsync(0);

    expect(age()).toBe('server text');
});

it('picks the form of the largest whole unit', () => {
    expect(ageForm(30)).toEqual(['now', 0]);
    expect(ageForm(60)).toEqual(['minute', 1]);
    expect(ageForm(42 * 60)).toEqual(['minutes', 42]);
    expect(ageForm(3600)).toEqual(['hour', 1]);
    expect(ageForm(26 * 3600)).toEqual(['day', 1]);
    expect(ageForm(3 * 86400)).toEqual(['days', 3]);
    expect(ageForm(-5)).toEqual(['now', 0]);
});
