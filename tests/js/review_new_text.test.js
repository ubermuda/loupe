/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReviewNewTextController from '../../assets/controllers/review_new_text_controller.js';

const STORAGE_KEY = 'loupe.review.newText';
const ANCHORS = [{ quote: 'careful', prefix: 'one ', suffix: ' step' }];
let application;
let painted;
const onPaint = (event) => {
    painted.push(event.detail.anchors);
};

beforeEach(() => {
    window.localStorage.clear();
    painted = [];
    application = Application.start();
    application.register('review-new-text', ReviewNewTextController);
});

afterEach(async () => {
    document.removeEventListener('review-new-text:paint', onPaint);
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    window.localStorage.clear();
    vi.unstubAllGlobals();
});

function respond(body, ok = true) {
    const fetcher = vi.fn(async () => ({
        ok,
        status: ok ? 200 : 500,
        json: async () => body,
    }));
    vi.stubGlobal('fetch', fetcher);

    return fetcher;
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

async function mount({ disabled = false } = {}) {
    document.body.innerHTML = `<div id="host"><button type="button" role="switch" aria-checked="false"
        ${disabled ? 'aria-disabled="true"' : ''} title="Highlight new text"
        data-controller="review-new-text"
        data-action="click->review-new-text#toggle"
        data-review-new-text-url-value="/new-text/2"
        data-review-new-text-no-previous-message-value="First version"
        data-review-new-text-refused-message-value="Cannot compare"
        data-review-new-text-error-message-value="Load failed"></button></div>`;
    document.addEventListener('review-new-text:paint', onPaint);
    await settle();

    return document.querySelector('button');
}

async function click(button) {
    button.click();
    await settle();
    await settle();
}

it('fetches once on the first turn on and paints the anchors', async () => {
    const fetcher = respond({ anchors: ANCHORS, reason: null });
    const button = await mount();

    await click(button);

    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0][0]).toBe('/new-text/2');
    expect(button.getAttribute('aria-checked')).toBe('true');
    expect(painted).toEqual([ANCHORS]);
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('1');
});

it('clears the paint and the stored choice on the second click, and reuses the anchors on the third', async () => {
    const fetcher = respond({ anchors: ANCHORS, reason: null });
    const button = await mount();

    await click(button);
    await click(button);
    expect(button.getAttribute('aria-checked')).toBe('false');
    expect(painted.at(-1)).toEqual([]);
    expect(window.localStorage.getItem(STORAGE_KEY)).toBeNull();

    await click(button);
    expect(painted.at(-1)).toEqual(ANCHORS);
    expect(fetcher).toHaveBeenCalledTimes(1);
});

it('fetches on connect when the browser remembers the switch on', async () => {
    window.localStorage.setItem(STORAGE_KEY, '1');
    const fetcher = respond({ anchors: ANCHORS, reason: null });

    const button = await mount();
    await settle();

    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(button.getAttribute('aria-checked')).toBe('true');
    expect(painted).toEqual([ANCHORS]);
});

it('stays quiet on connect when nothing is remembered', async () => {
    const fetcher = respond({ anchors: ANCHORS, reason: null });

    const button = await mount();

    expect(fetcher).not.toHaveBeenCalled();
    expect(button.getAttribute('aria-checked')).toBe('false');
});

it('does nothing on a switch the server disabled, even with the choice remembered', async () => {
    window.localStorage.setItem(STORAGE_KEY, '1');
    const fetcher = respond({ anchors: ANCHORS, reason: null });
    const button = await mount({ disabled: true });

    await click(button);

    expect(fetcher).not.toHaveBeenCalled();
    expect(button.getAttribute('aria-checked')).toBe('false');
    expect(button.getAttribute('title')).toBe('Highlight new text');
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('1');
});

it('disables itself with the reason when the diff was refused, and keeps the stored choice', async () => {
    respond({ anchors: [], reason: 'diff-refused' });
    const button = await mount();

    await click(button);

    expect(button.getAttribute('aria-disabled')).toBe('true');
    expect(button.getAttribute('aria-checked')).toBe('false');
    expect(button.getAttribute('title')).toBe('Cannot compare');
    expect(painted.at(-1)).toEqual([]);
});

it('names the missing earlier version', async () => {
    respond({ anchors: [], reason: 'no-previous-version' });
    const button = await mount();

    await click(button);

    expect(button.getAttribute('aria-disabled')).toBe('true');
    expect(button.getAttribute('title')).toBe('First version');
});

it('turns off with an error title when the request fails, and may retry', async () => {
    respond({}, false);
    const button = await mount();

    await click(button);

    expect(button.getAttribute('aria-checked')).toBe('false');
    expect(button.getAttribute('aria-disabled')).toBeNull();
    expect(button.getAttribute('title')).toBe('Load failed');

    const fetcher = respond({ anchors: ANCHORS, reason: null });
    await click(button);
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(button.getAttribute('aria-checked')).toBe('true');
    expect(button.getAttribute('title')).toBe('Highlight new text');
});

it('still works when storage is blocked', async () => {
    respond({ anchors: ANCHORS, reason: null });
    const button = await mount();
    const blocked = vi
        .spyOn(Storage.prototype, 'setItem')
        .mockImplementation(() => {
            throw new Error('blocked');
        });

    await click(button);

    expect(button.getAttribute('aria-checked')).toBe('true');
    blocked.mockRestore();
});

function pending() {
    const requests = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(
            () =>
                new Promise((resolve, reject) => {
                    requests.push({ resolve, reject });
                }),
        ),
    );

    return requests;
}

it('is busy while the passages load, and idle once they arrive', async () => {
    const requests = pending();
    const button = await mount();

    await click(button);
    expect(button.getAttribute('aria-busy')).toBe('true');

    requests[0].resolve({
        ok: true,
        json: async () => ({ anchors: ANCHORS, reason: null }),
    });
    await settle();
    await settle();

    expect(button.hasAttribute('aria-busy')).toBe(false);
    expect(painted).toEqual([ANCHORS]);
});

it('is idle again when the load fails', async () => {
    const requests = pending();
    const button = await mount();

    await click(button);
    requests[0].reject(new TypeError('Failed to fetch'));
    await settle();
    await settle();

    expect(button.hasAttribute('aria-busy')).toBe(false);
    expect(button.getAttribute('title')).toBe('Load failed');
});

it('is idle at once when turned off during the load', async () => {
    pending();
    const button = await mount();

    await click(button);
    await click(button);

    expect(button.hasAttribute('aria-busy')).toBe(false);
    expect(button.getAttribute('aria-checked')).toBe('false');
});
