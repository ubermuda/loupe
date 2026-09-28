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
const MANIFEST = '/projects/p/board/manifest';

let application;
let reloads;
let change;
let reconnect;
let setStatus;
let answer;

const stream = (body = '<turbo-stream></turbo-stream>') => ({
    ok: true,
    headers: new Headers({ 'Content-Type': STREAM }),
    text: () => Promise.resolve(body),
});

/** Answers the manifest read with the given response, and each placement with a stream. */
function answerManifest(response) {
    answer = (url, options) =>
        url === MANIFEST
            ? typeof response === 'function'
                ? response(options)
                : Promise.resolve(response)
            : Promise.resolve(stream());
}

const json = (body) => ({
    ok: true,
    headers: new Headers({ 'Content-Type': 'application/json' }),
    json: () => Promise.resolve(body),
});

const placements = () =>
    fetch.mock.calls
        .map(([url]) => url)
        .filter((url) => url !== MANIFEST)
        .map((url) => url.split('/')[5]);

const manifestReads = () =>
    fetch.mock.calls.filter(([url]) => url === MANIFEST);

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
            data-board-live-placeholder-value="${PLACEHOLDER}"
            data-board-live-manifest-value="${MANIFEST}">
        <p data-board-live-target="paused" role="status" data-message="Live updates paused"></p>
        <div id="board" data-board-structure-digest="frame">
            <div class="lp-board__group" data-column="k">
                <article id="board-card-a" class="lp-board-card" data-card-id="a" data-card-digest="old"></article>
                <article id="board-card-b" class="lp-board-card" data-card-id="b" data-card-digest="old"></article>
            </div>
            <a class="lp-board-list__row" data-card-id="a" data-card-digest="old"></a>
            <a class="lp-board-list__row" data-card-id="b" data-card-digest="old"></a>
        </div>
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
        vi.fn((url, options) => answer(url, options)),
    );
    on.mockImplementation((types, handler, options = {}) => {
        change = handler;
        reconnect = options.onReconnect;
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

it('listens for card changes and for a reconnect', () => {
    expect(on).toHaveBeenCalledWith(
        'board.card_changed',
        expect.any(Function),
        expect.objectContaining({ onReconnect: expect.any(Function) }),
    );
});

it('does not read the manifest when it first connects', async () => {
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).not.toHaveBeenCalled();
    expect(reloads).toBe(0);
});

it('reads the manifest after a reconnect and fetches a card whose digest changed', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'new', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(150);

    const [url, options] = manifestReads()[0];
    expect(url).toBe(MANIFEST);
    expect(options.headers.Accept).toBe('application/json');
    expect(options.credentials).toBe('same-origin');
    expect(options.signal).toBeInstanceOf(AbortSignal);
    expect(placements()).toEqual(['b']);
    expect(reloads).toBe(0);
});

it('fetches a card the manifest has and the page does not', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['c', 'new', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(150);

    expect(placements()).toEqual(['c']);
});

it('fetches a removed card first, then the rest in manifest order', async () => {
    answerManifest(
        json({
            cards: [
                ['c', 'new', 'k'],
                ['a', 'changed', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(150);

    expect(placements()).toEqual(['b', 'c', 'a']);
    expect(reloads).toBe(0);
});

it('does nothing more when the page already shows every card', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(manifestReads()).toHaveLength(1);
    expect(placements()).toEqual([]);
    expect(reloads).toBe(0);
});

it('fetches a card that moved in its column, and not its unchanged neighbours', async () => {
    document.querySelector('.lp-board__group').insertAdjacentHTML(
        'beforeend',
        `<article id="board-card-c" class="lp-board-card" data-card-id="c" data-card-digest="old"></article>
            <article id="board-card-d" class="lp-board-card" data-card-id="d" data-card-digest="old"></article>`,
    );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['c', 'old', 'k'],
                ['d', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['b']);
    expect(reloads).toBe(0);
});

it('compares the order in each lane cell on its own', async () => {
    document.getElementById('board').insertAdjacentHTML(
        'beforeend',
        `<div class="lp-board-lane__cell" data-column="k" data-lane="epic">
            <article id="board-card-c" class="lp-board-card" data-card-id="c" data-card-digest="old"></article>
        </div>`,
    );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['c', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual([]);
});

it('fetches a list row that moved across lane cells while each cell kept its order', async () => {
    document.getElementById('board').innerHTML = `
        <div class="lp-board-lane__cell" data-column="k" data-lane="epic">
            <article id="board-card-a" class="lp-board-card" data-card-id="a" data-card-digest="old"></article>
        </div>
        <div class="lp-board-lane__cell" data-column="k" data-lane="other">
            <article id="board-card-b" class="lp-board-card" data-card-id="b" data-card-digest="old"></article>
        </div>
        <div class="lp-board-list">
            <a class="lp-board-list__row" data-card-id="epic"></a>
            <a class="lp-board-list__row" data-card-id="a" data-card-digest="old"></a>
            <a class="lp-board-list__row" data-card-id="b" data-card-digest="old"></a>
        </div>`;
    answerManifest(
        json({
            cards: [
                ['b', 'old', 'k'],
                ['a', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['a']);
    expect(reloads).toBe(0);
});

it('fetches a lane epic whose list row moved past an ordinary row', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'afterbegin',
            '<a class="lp-board-list__row" data-card-id="epic"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
                ['epic', 'old', 'k', true],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['epic']);
    expect(reloads).toBe(0);
});

it('never counts a lane head as an added or a removed card', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a class="lp-board-list__row" data-card-id="epic"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
                ['epic', 'new', 'k', true],
                ['gone', 'new', 'k', true],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual([]);
    expect(reloads).toBe(0);
});

it('fetches nothing when the list rows keep the manifest order', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'afterbegin',
            '<a class="lp-board-list__row" data-card-id="epic"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(manifestReads()).toHaveLength(1);
    expect(placements()).toEqual([]);
});

it('fetches one card when only a history total changed, so the history links refresh', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a id="board-history-done" data-history-total="4"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
            terminalTotals: { done: 3 },
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['a']);
    expect(reloads).toBe(0);
});

it('fetches nothing more when the history totals match the page', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a id="board-history-done" data-history-total="4"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
            terminalTotals: { done: 4 },
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(manifestReads()).toHaveLength(1);
    expect(placements()).toEqual([]);
    expect(reloads).toBe(0);
});

it('fetches no extra card for a history total when a changed card refreshes the links', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a id="board-history-done" data-history-total="4"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'new', 'k'],
            ],
            structure: 'frame',
            terminalTotals: { done: 3 },
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['b']);
});

it('reloads when a history total changed and the board has no card to fetch', async () => {
    document.getElementById('board').innerHTML =
        '<a id="board-history-done" data-history-total="1"></a>';
    answerManifest(
        json({ cards: [], structure: 'frame', terminalTotals: { done: 0 } }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual([]);
    expect(reloads).toBe(1);
});

it('ignores the list rows, which carry a digest too', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a class="lp-board-list__row" data-card-id="z" data-card-digest="old"></a>',
        );
    document.querySelector('.lp-board-list__row').dataset.cardDigest = 'stale';
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual([]);
    expect(reloads).toBe(0);
});

it('reloads the board and fetches no card when the structure changed', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'new', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'other',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(reloads).toBe(1);
    expect(placements()).toEqual([]);
});

it('reads the structure digest at compare time, after the frame re-rendered', async () => {
    let finish;
    answerManifest(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    document.getElementById('board').dataset.boardStructureDigest = 'renamed';
    finish(
        json({
            cards: [
                ['a', 'old', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'renamed',
        }),
    );
    await vi.advanceTimersByTimeAsync(1000);

    expect(reloads).toBe(0);
});

it.each([
    [
        'a response that is not 2xx',
        () => Promise.resolve({ ok: false, json: () => Promise.resolve({}) }),
    ],
    ['a network error', () => Promise.reject(new TypeError('offline'))],
    [
        'a body that is not JSON',
        () =>
            Promise.resolve({
                ok: true,
                json: () => Promise.reject(new SyntaxError('bad')),
            }),
    ],
    [
        'a manifest with no structure',
        () => Promise.resolve(json({ cards: [] })),
    ],
    [
        'a manifest with no cards',
        () => Promise.resolve(json({ structure: 'frame' })),
    ],
    [
        'a manifest with a bad card entry',
        () => Promise.resolve(json({ cards: [['a']], structure: 'frame' })),
    ],
    [
        'a manifest card entry with no column',
        () =>
            Promise.resolve(
                json({ cards: [['a', 'old']], structure: 'frame' }),
            ),
    ],
])('reloads the board after %s', async (label, response) => {
    answerManifest(response);
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(reloads).toBe(1);
    expect(placements()).toEqual([]);
});

it('gives up on a stalled manifest read and reloads', async () => {
    answerManifest(
        (options) =>
            new Promise((resolve, reject) => {
                options.signal.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(9999);
    expect(reloads).toBe(0);

    await vi.advanceTimersByTimeAsync(1);
    expect(reloads).toBe(1);
});

it('aborts an older manifest read on a second reconnect and uses the newer one', async () => {
    const signals = [];
    const finishes = [];
    answerManifest(
        (options) =>
            new Promise((resolve, reject) => {
                signals.push(options.signal);
                finishes.push(resolve);
                options.signal.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    reconnect();
    await vi.advanceTimersByTimeAsync(0);

    expect(signals).toHaveLength(2);
    expect(signals[0].aborted).toBe(true);
    expect(signals[1].aborted).toBe(false);
    expect(reloads).toBe(0);

    finishes[1](
        json({
            cards: [
                ['a', 'new', 'k'],
                ['b', 'old', 'k'],
            ],
            structure: 'frame',
        }),
    );
    await vi.advanceTimersByTimeAsync(150);
    expect(placements()).toEqual(['a']);
    expect(reloads).toBe(0);
});

it('aborts a manifest read when it disconnects, and does not reload', async () => {
    let signal;
    answerManifest(
        (options) =>
            new Promise((resolve, reject) => {
                signal = options.signal;
                options.signal.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(0);

    const wrapper = document.getElementById('wrapper');
    wrapper.removeAttribute('data-controller');
    await vi.advanceTimersByTimeAsync(0);

    expect(signal.aborted).toBe(true);
    expect(reloads).toBe(0);
    expect(vi.getTimerCount()).toBe(0);
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

it('marks a card another person moved in its column', async () => {
    receive('b');
    await vi.advanceTimersByTimeAsync(150);
    card().before(document.getElementById('board-card-b'));
    placed('b', 'old');

    expect(
        document
            .getElementById('board-card-b')
            .classList.contains('lp-board-card--flash'),
    ).toBe(true);
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

it('gives up on a stalled placement, reloads, and still fetches the next card', async () => {
    answer = (url, options) =>
        url.includes('/a/')
            ? new Promise((resolve, reject) => {
                  options.signal.addEventListener('abort', () =>
                      reject(new DOMException('Aborted', 'AbortError')),
                  );
              })
            : Promise.resolve({
                  ok: true,
                  headers: new Headers({ 'Content-Type': STREAM }),
                  text: () => Promise.resolve('b'),
              });
    receive('a');
    receive('b');
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(9999);
    expect(fetch).toHaveBeenCalledOnce();
    expect(reloads).toBe(0);

    await vi.advanceTimersByTimeAsync(1);
    expect(reloads).toBe(1);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(fetch.mock.calls[1][0]).toBe('/projects/p/board/cards/b/placement');
    expect(renderStreamMessage).toHaveBeenCalledWith('b');
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
    expect(reloads).toBe(1);
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
