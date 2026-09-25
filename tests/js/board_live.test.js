/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BoardLiveController from '../../assets/controllers/board_live_controller.js';
import { on, status } from '../../assets/lib/live.js';

vi.mock('@hotwired/turbo', () => ({ renderStreamMessage: vi.fn() }));
vi.mock('../../assets/lib/live.js', () => ({
    on: vi.fn(() => () => {}),
    status: vi.fn(() => () => {}),
}));

const PLACEHOLDER = '00000000-0000-7000-8000-000000000000';
const STREAM = 'text/vnd.turbo-stream.html; charset=UTF-8';

let application;
let reloads;
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

const card = () => document.getElementById('board-card-a');

beforeEach(async () => {
    document.body.innerHTML = `<div id="wrapper" data-controller="board-live"
            data-board-live-placement-value="/projects/p/board/cards/${PLACEHOLDER}/placement"
            data-board-live-placeholder-value="${PLACEHOLDER}">
        <p data-board-live-target="paused" role="status" data-message="Live updates paused"></p>
        <article id="board-card-a" data-card-digest="old"></article>
        <article id="board-card-b" data-card-digest="old"></article>
    </div>`;
    reloads = 0;
    document
        .getElementById('wrapper')
        .addEventListener('board-live:reload', () => (reloads += 1));
    answer = () =>
        Promise.resolve({
            ok: true,
            headers: new Headers({ 'Content-Type': STREAM }),
            text: () => Promise.resolve('<turbo-stream></turbo-stream>'),
        });
    vi.stubGlobal(
        'fetch',
        vi.fn(() => answer()),
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
    renderStreamMessage.mockClear();

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
    expect(reloads).toBe(0);

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

it('reloads the board when a placement cannot be applied', async () => {
    receive('a');
    await vi.advanceTimersByTimeAsync(150);
    document.dispatchEvent(
        new CustomEvent('board:place-missed', { detail: { cardId: 'a' } }),
    );

    expect(reloads).toBe(1);
});

it('reloads the board when the placement request fails', async () => {
    answer = () =>
        Promise.resolve({
            ok: false,
            headers: new Headers({ 'Content-Type': 'text/html' }),
            text: () => Promise.resolve('<html></html>'),
        });
    receive('a');
    await vi.advanceTimersByTimeAsync(150);

    expect(renderStreamMessage).not.toHaveBeenCalled();
    expect(reloads).toBe(1);
});

it('reloads the board when the placement request cannot reach the server', async () => {
    answer = () => Promise.reject(new TypeError('offline'));
    receive('a');
    await vi.advanceTimersByTimeAsync(150);

    expect(reloads).toBe(1);
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
