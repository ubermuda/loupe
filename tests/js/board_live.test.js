/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BoardLiveController from '../../assets/controllers/board_live_controller.js';
import { on, status } from '../../assets/lib/live.js';

vi.mock('@hotwired/turbo', () => ({ renderStreamMessage: vi.fn() }));
vi.mock('../../assets/lib/live.js', () => ({
    on: vi.fn(() => () => {}),
    status: vi.fn(() => () => {}),
}));

const PLACEHOLDER = '00000000-0000-7000-8000-000000000000';
const STREAM = 'text/vnd.turbo-stream.html; charset=UTF-8';

const STALE_TEXT = 'This card may be out of date';

let application;
let change;
let setStatus;
let answer;

function receive(cardId, flags = {}) {
    change({
        type: 'board.card_changed',
        cardId,
        change: 'updated',
        local: false,
        own: false,
        ...flags,
    });
}

function placed(cardId, digest) {
    const card = document.getElementById(`board-card-${cardId}`);
    card.dataset.cardDigest = digest;
    card.dispatchEvent(
        new CustomEvent('board:placed', { bubbles: true, detail: { cardId } }),
    );
}

function missed(cardId) {
    document.dispatchEvent(
        new CustomEvent('board:place-missed', { detail: { cardId } }),
    );
}

const card = () => document.getElementById('board-card-a');
const row = () => document.getElementById('board-row-a');
const stream = (body = '<turbo-stream></turbo-stream>') => ({
    ok: true,
    status: 200,
    headers: new Headers({ 'Content-Type': STREAM }),
    text: () => Promise.resolve(body),
});
const failure = (status, type = 'text/html') => ({
    ok: status >= 200 && status < 300,
    status,
    headers: new Headers({ 'Content-Type': type }),
    text: () => Promise.resolve('<html></html>'),
});
const isStale = () =>
    card().classList.contains('lp-board-card--stale') ||
    card().hasAttribute('data-board-stale') ||
    row().classList.contains('lp-board-list__row--stale');

beforeEach(async () => {
    document.body.innerHTML = `<div id="wrapper" data-controller="board-live"
            data-board-live-placement-value="/projects/p/board/cards/${PLACEHOLDER}/placement"
            data-board-live-placeholder-value="${PLACEHOLDER}"
            data-board-live-stale-value="${STALE_TEXT}">
        <p data-board-live-target="paused" role="status" data-message="Live updates paused"></p>
        <article id="board-card-a" data-card-digest="old"></article>
        <article id="board-card-b" data-card-digest="old"></article>
        <a id="board-row-a" href="#"></a>
    </div>`;
    answer = () => Promise.resolve(stream());
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options) => answer(url, options)),
    );
    on.mockImplementation((types, handler) => {
        change = handler;
        return () => {};
    });
    status.mockImplementation((listener) => {
        setStatus = listener;
        listener('live');
        return () => {};
    });
    renderStreamMessage.mockReset();
    vi.spyOn(Math, 'random').mockReturnValue(0);

    application = Application.start();
    application.register('board-live', BoardLiveController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    vi.useFakeTimers();
});

afterEach(async () => {
    vi.useRealTimers();
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

it('listens for card changes', () => {
    expect(on).toHaveBeenCalledWith('board.card_changed', expect.any(Function));
});

it('fetches a card once for a burst of messages, 150 ms after the last one', async () => {
    receive('a');
    await vi.advanceTimersByTimeAsync(100);
    receive('a');
    await vi.advanceTimersByTimeAsync(100);
    receive('a');
    await vi.advanceTimersByTimeAsync(149);
    expect(fetch).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);
    expect(fetch).toHaveBeenCalledOnce();
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe('/projects/p/board/cards/a/placement');
    expect(options.headers.Accept).toBe('text/vnd.turbo-stream.html');
    expect(options.credentials).toBe('same-origin');
    expect(renderStreamMessage).toHaveBeenCalledWith(
        '<turbo-stream></turbo-stream>',
    );
});

it('fetches each card that changed', async () => {
    receive('a');
    receive('b');
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch.mock.calls.map(([url]) => url)).toEqual([
        '/projects/p/board/cards/a/placement',
        '/projects/p/board/cards/b/placement',
    ]);
});

it('runs one placement at a time, so an older answer never renders after a newer one', async () => {
    const finishes = [];
    answer = () =>
        new Promise((resolve) => {
            finishes.push(resolve);
        });
    const stream = (body) => ({
        ok: true,
        headers: new Headers({ 'Content-Type': STREAM }),
        text: () => Promise.resolve(body),
    });
    receive('a');
    receive('b');
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledOnce();

    finishes[0](stream('a'));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetch).toHaveBeenCalledTimes(2);
    finishes[1](stream('b'));
    await vi.advanceTimersByTimeAsync(0);
    expect(renderStreamMessage.mock.calls.map(([html]) => html)).toEqual([
        'a',
        'b',
    ]);
});

it('still fetches a change that names this page as its origin', async () => {
    receive('a', { own: true });
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledOnce();
});

it('fetches a change this page emits', async () => {
    receive('a', { local: true, own: true });
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledOnce();
});

it('fetches again after the answer when a message arrives while a fetch runs', async () => {
    let finish;
    answer = () =>
        new Promise((resolve) => {
            finish = resolve;
        });
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    receive('a');
    await vi.advanceTimersByTimeAsync(500);
    expect(fetch).toHaveBeenCalledOnce();

    finish({
        ok: true,
        headers: new Headers({ 'Content-Type': STREAM }),
        text: () => Promise.resolve(''),
    });
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledTimes(2);
});

it('marks a card another person changed, for a moment', async () => {
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'new');

    expect(card().classList.contains('lp-board-card--flash')).toBe(true);
    await vi.advanceTimersByTimeAsync(1500);
    expect(card().classList.contains('lp-board-card--flash')).toBe(false);
});

it('does not mark a card whose face did not change', async () => {
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'old');

    expect(card().classList.contains('lp-board-card--flash')).toBe(false);
});

it('does not mark a change this page made', async () => {
    receive('a', { own: true });
    receive('b', { local: true, own: true });
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'new');
    placed('b', 'new');

    expect(document.querySelectorAll('.lp-board-card--flash')).toHaveLength(0);
});

it('marks a card when one message of a burst came from another person', async () => {
    receive('a', { own: true });
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'new');

    expect(card().classList.contains('lp-board-card--flash')).toBe(true);
});

it('holds a card with a pending move, and fetches it once the move settles', async () => {
    card().setAttribute('aria-busy', 'true');
    receive('a');
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).not.toHaveBeenCalled();

    card().removeAttribute('aria-busy');
    await vi.advanceTimersByTimeAsync(250);
    expect(fetch).toHaveBeenCalledOnce();
});

it('holds a card that is being dragged', async () => {
    card().classList.add('lp-board-card--dragging');
    receive('a');
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).not.toHaveBeenCalled();

    card().classList.remove('lp-board-card--dragging');
    await vi.advanceTimersByTimeAsync(250);
    expect(fetch).toHaveBeenCalledOnce();
});

it('drops a placement that returns while the card is dragged, and fetches it again after the drag', async () => {
    let finish;
    answer = () =>
        new Promise((resolve) => {
            finish = resolve;
        });
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledOnce();

    card().classList.add('lp-board-card--dragging');
    finish({
        ok: true,
        headers: new Headers({ 'Content-Type': STREAM }),
        text: () => Promise.resolve('<turbo-stream></turbo-stream>'),
    });
    await vi.advanceTimersByTimeAsync(1000);
    expect(renderStreamMessage).not.toHaveBeenCalled();

    answer = () =>
        Promise.resolve({
            ok: true,
            headers: new Headers({ 'Content-Type': STREAM }),
            text: () => Promise.resolve('<turbo-stream></turbo-stream>'),
        });
    card().classList.remove('lp-board-card--dragging');
    await vi.advanceTimersByTimeAsync(250);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(renderStreamMessage).toHaveBeenCalledOnce();
    placed('a', 'new');
    expect(card().classList.contains('lp-board-card--flash')).toBe(true);
});

it('keeps the mark for the full time after a second change', async () => {
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'new');
    await vi.advanceTimersByTimeAsync(1000);

    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'newer');
    await vi.advanceTimersByTimeAsync(1000);
    expect(card().classList.contains('lp-board-card--flash')).toBe(true);

    await vi.advanceTimersByTimeAsync(500);
    expect(card().classList.contains('lp-board-card--flash')).toBe(false);
});

it('clears the mark timers when it disconnects', async () => {
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'new');
    expect(vi.getTimerCount()).toBe(1);

    document.getElementById('wrapper').removeAttribute('data-controller');
    await vi.advanceTimersByTimeAsync(0);
    expect(vi.getTimerCount()).toBe(0);
});

it('still fetches the next card after a placement request fails', async () => {
    answer = (url) =>
        url.includes('/a/')
            ? Promise.reject(new TypeError('offline'))
            : Promise.resolve({
                  ok: true,
                  headers: new Headers({ 'Content-Type': STREAM }),
                  text: () => Promise.resolve('b'),
              });
    receive('a');
    receive('b');
    await vi.advanceTimersByTimeAsync(150);

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(renderStreamMessage).toHaveBeenCalledWith('b');
});

it('fetches a card once when a message arrives while it waits in the queue', async () => {
    let finish;
    answer = (url) =>
        url.includes('/a/')
            ? new Promise((resolve) => {
                  finish = resolve;
              })
            : Promise.resolve({
                  ok: true,
                  headers: new Headers({ 'Content-Type': STREAM }),
                  text: () => Promise.resolve('b'),
              });
    receive('a');
    receive('b');
    await vi.advanceTimersByTimeAsync(150);
    receive('b');
    finish({
        ok: true,
        headers: new Headers({ 'Content-Type': STREAM }),
        text: () => Promise.resolve('a'),
    });
    await vi.advanceTimersByTimeAsync(1000);

    expect(fetch.mock.calls.map(([url]) => url)).toEqual([
        '/projects/p/board/cards/a/placement',
        '/projects/p/board/cards/b/placement',
    ]);
});

it('writes the paused sign into its live region only while live updates are paused', () => {
    const sign = document.querySelector('[data-board-live-target="paused"]');
    expect(sign.hidden).toBe(false);
    expect(sign.textContent).toBe('');

    setStatus('paused');
    expect(sign.textContent).toBe('Live updates paused');

    setStatus('live');
    expect(sign.textContent).toBe('');

    setStatus('paused');
    setStatus('off');
    expect(sign.textContent).toBe('');
    expect(sign.hidden).toBe(false);
});

it.each([
    ['cannot reach the server', () => Promise.reject(new TypeError('offline'))],
    ['answers 500', () => Promise.resolve(failure(500))],
    ['answers 503', () => Promise.resolve(failure(503))],
    ['answers 429', () => Promise.resolve(failure(429))],
    ['answers 408', () => Promise.resolve(failure(408))],
    [
        'answers a page that is not a stream',
        () => Promise.resolve(failure(200)),
    ],
])(
    'retries a placement request that %s, after one second',
    async (name, fail) => {
        answer = fail;
        receive('a');
        await vi.advanceTimersByTimeAsync(150);
        expect(fetch).toHaveBeenCalledOnce();
        expect(renderStreamMessage).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(999);
        expect(fetch).toHaveBeenCalledOnce();
        await vi.advanceTimersByTimeAsync(1);
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(isStale()).toBe(false);
    },
);

it('gives up on a stalled placement, retries it, and still fetches the next card', async () => {
    answer = (url, options) =>
        url.includes('/a/')
            ? new Promise((resolve, reject) => {
                  options.signal.addEventListener('abort', () =>
                      reject(new DOMException('Aborted', 'AbortError')),
                  );
              })
            : Promise.resolve(stream('b'));
    receive('a');
    receive('b');
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(9999);
    expect(fetch).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(1);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(fetch.mock.calls[1][0]).toBe('/projects/p/board/cards/b/placement');
    expect(renderStreamMessage).toHaveBeenCalledWith('b');

    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(fetch.mock.calls[2][0]).toBe('/projects/p/board/cards/a/placement');
});

it.each([403, 404, 400, 401, 410])(
    'marks the card stale at once, with no retry, when the placement answers %i',
    async (status) => {
        answer = () => Promise.resolve(failure(status));
        receive('a');
        await vi.advanceTimersByTimeAsync(150);
        expect(isStale()).toBe(true);

        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).toHaveBeenCalledOnce();
    },
);

it('waits 1, 3 and 9 seconds between retries, then marks the card stale', async () => {
    answer = () => Promise.resolve(failure(503));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    for (const [wait, calls] of [
        [1000, 2],
        [3000, 3],
        [9000, 4],
    ]) {
        await vi.advanceTimersByTimeAsync(wait - 1);
        expect(fetch).toHaveBeenCalledTimes(calls - 1);
        expect(isStale()).toBe(false);
        await vi.advanceTimersByTimeAsync(1);
        expect(fetch).toHaveBeenCalledTimes(calls);
    }

    expect(isStale()).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
    await vi.advanceTimersByTimeAsync(60000);
    expect(fetch).toHaveBeenCalledTimes(4);
});

it('adds up to 20 percent random time to each retry wait', async () => {
    Math.random.mockReturnValue(1);
    answer = () => Promise.resolve(failure(503));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    for (const [wait, calls] of [
        [1200, 2],
        [3600, 3],
        [10800, 4],
    ]) {
        await vi.advanceTimersByTimeAsync(wait - 1);
        expect(fetch).toHaveBeenCalledTimes(calls - 1);
        await vi.advanceTimersByTimeAsync(1);
        expect(fetch).toHaveBeenCalledTimes(calls);
    }
    expect(Math.random).toHaveBeenCalledTimes(3);
});

it('marks the stale card and its list row for the eye and for a screen reader', async () => {
    answer = () => Promise.resolve(failure(404));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);

    for (const [element, className] of [
        [card(), 'lp-board-card--stale'],
        [row(), 'lp-board-list__row--stale'],
    ]) {
        expect(element.classList.contains(className)).toBe(true);
        expect(element.hasAttribute('data-board-stale')).toBe(true);
        expect(element.title).toBe(STALE_TEXT);
        const hidden = element.querySelectorAll('.sr-only');
        expect(hidden).toHaveLength(1);
        expect(hidden[0].textContent).toBe(STALE_TEXT);
    }
});

it('keeps one hidden text when a stale card is marked again', async () => {
    answer = () => Promise.resolve(failure(404));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    receive('a');
    await vi.advanceTimersByTimeAsync(150);

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(card().querySelectorAll('.sr-only')).toHaveLength(1);
    expect(row().querySelectorAll('.sr-only')).toHaveLength(1);
});

it('clears the stale mark when the card is placed again', async () => {
    answer = () => Promise.resolve(failure(404));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    expect(isStale()).toBe(true);

    answer = () => Promise.resolve(stream());
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    placed('a', 'old');

    for (const element of [card(), row()]) {
        expect(element.className).toBe('');
        expect(element.hasAttribute('data-board-stale')).toBe(false);
        expect(element.hasAttribute('title')).toBe(false);
        expect(element.querySelectorAll('.sr-only')).toHaveLength(0);
    }
});

describe('a lane epic', () => {
    const head = () =>
        document.querySelector('#board-lane-e .lp-board-lane__head');

    beforeEach(() => {
        document
            .getElementById('wrapper')
            .insertAdjacentHTML(
                'beforeend',
                '<section id="board-lane-e"><header class="lp-board-lane__head"><a class="lp-board-lane__title">Epic</a></header></section><a id="board-row-e" href="#"></a>',
            );
    });

    it('marks the head of its lane stale for the eye and for a screen reader', async () => {
        answer = () => Promise.resolve(failure(404));
        receive('e');
        await vi.advanceTimersByTimeAsync(150);

        expect(head().classList.contains('lp-board-lane__head--stale')).toBe(
            true,
        );
        expect(head().hasAttribute('data-board-stale')).toBe(true);
        expect(head().title).toBe(STALE_TEXT);
        const hidden = head().querySelectorAll('.sr-only');
        expect(hidden).toHaveLength(1);
        expect(hidden[0].textContent).toBe(STALE_TEXT);
        expect(
            document
                .getElementById('board-row-e')
                .hasAttribute('data-board-stale'),
        ).toBe(true);
    });

    it('clears the stale mark of the head when the lane is placed again', async () => {
        answer = () => Promise.resolve(failure(404));
        receive('e');
        await vi.advanceTimersByTimeAsync(150);

        document.getElementById('board-lane-e').dispatchEvent(
            new CustomEvent('board:placed', {
                bubbles: true,
                detail: { cardId: 'e' },
            }),
        );

        expect(head().className).toBe('lp-board-lane__head');
        expect(head().hasAttribute('data-board-stale')).toBe(false);
        expect(head().hasAttribute('title')).toBe(false);
        expect(head().querySelectorAll('.sr-only')).toHaveLength(0);
    });
});

it('counts a new change as a fresh start, and cancels the waiting retry', async () => {
    answer = () => Promise.resolve(failure(503));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).toHaveBeenCalledTimes(2);

    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledTimes(3);

    await vi.advanceTimersByTimeAsync(999);
    expect(fetch).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetch).toHaveBeenCalledTimes(4);
    expect(isStale()).toBe(false);
});

it('counts a change that arrives during a failed fetch as a fresh start', async () => {
    let finish;
    answer = () =>
        new Promise((resolve) => {
            finish = resolve;
        });
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    receive('a');
    finish(failure(503));
    await vi.advanceTimersByTimeAsync(149);
    expect(fetch).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetch).toHaveBeenCalledTimes(2);

    answer = () => Promise.resolve(failure(503));
    finish(failure(503));
    await vi.advanceTimersByTimeAsync(999);
    expect(fetch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetch).toHaveBeenCalledTimes(3);
});

it('counts no attempt while the card waits for a drag to settle', async () => {
    answer = () => Promise.resolve(failure(503));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    card().setAttribute('aria-busy', 'true');
    await vi.advanceTimersByTimeAsync(30000);
    expect(fetch).toHaveBeenCalledOnce();

    card().removeAttribute('aria-busy');
    await vi.advanceTimersByTimeAsync(200);
    expect(fetch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(2999);
    expect(fetch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(isStale()).toBe(false);
});

it('lets another card through while one card waits to retry', async () => {
    answer = (url) =>
        Promise.resolve(url.includes('/a/') ? failure(503) : stream('b'));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    receive('b');
    await vi.advanceTimersByTimeAsync(150);

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(renderStreamMessage).toHaveBeenCalledWith('b');
});

it.each([
    ['at once', 0],
    ['after a repaint', 16],
])(
    'counts a placement the page cannot apply %s as one failed attempt',
    async (name, delay) => {
        renderStreamMessage.mockImplementation(() =>
            delay === 0 ? missed('a') : setTimeout(() => missed('a'), delay),
        );
        receive('a');
        await vi.advanceTimersByTimeAsync(150);
        for (const [wait, calls] of [
            [1000 + delay, 2],
            [3000 + delay, 3],
            [9000 + delay, 4],
        ]) {
            await vi.advanceTimersByTimeAsync(wait - 1);
            expect(fetch).toHaveBeenCalledTimes(calls - 1);
            await vi.advanceTimersByTimeAsync(1);
            expect(fetch).toHaveBeenCalledTimes(calls);
        }

        await vi.advanceTimersByTimeAsync(delay);
        expect(isStale()).toBe(true);
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).toHaveBeenCalledTimes(4);
    },
);

it('fetches a card whose placement the page could not apply, with no change message', async () => {
    missed('b');
    await vi.advanceTimersByTimeAsync(999);
    expect(fetch).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(1);
    expect(fetch).toHaveBeenCalledOnce();
    expect(fetch.mock.calls[0][0]).toBe('/projects/p/board/cards/b/placement');
});

it('counts no miss for an older placement while a newer fetch of the card runs', async () => {
    let finish;
    answer = () =>
        new Promise((resolve) => {
            finish = resolve;
        });
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    missed('a');
    finish(stream());
    await vi.advanceTimersByTimeAsync(0);
    placed('a', 'old');

    await vi.advanceTimersByTimeAsync(60000);
    expect(fetch).toHaveBeenCalledOnce();
});

it('clears a waiting retry when it disconnects', async () => {
    answer = () => Promise.resolve(failure(503));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    expect(vi.getTimerCount()).toBe(1);

    document.getElementById('wrapper').removeAttribute('data-controller');
    await vi.advanceTimersByTimeAsync(0);
    expect(vi.getTimerCount()).toBe(0);
});

it('still marks a card another person changed when its placement failed once', async () => {
    answer = () => Promise.resolve(failure(500));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    answer = () => Promise.resolve(stream());
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).toHaveBeenCalledTimes(2);
    placed('a', 'new');

    expect(card().classList.contains('lp-board-card--flash')).toBe(true);
});

it('still marks a card another person changed when its placement missed once', async () => {
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    missed('a');
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).toHaveBeenCalledTimes(2);
    placed('a', 'new');

    expect(card().classList.contains('lp-board-card--flash')).toBe(true);
});
