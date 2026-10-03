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
const MANIFEST = '/projects/p/board/manifest';
const STRUCTURE = '/projects/p/board/structure';

const STALE_TEXT = 'This card may be out of date';
const FAILED_TEXT = 'Live updates stopped. Reload the page to catch up.';

let application;
let change;
let reconnect;
let columnsChanged;
let columnsOptions;
let setStatus;
let answer;
let dispatch;

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
        .filter((url) => url !== MANIFEST && url !== STRUCTURE)
        .map((url) => url.split('/')[5]);

const manifestReads = () =>
    fetch.mock.calls.filter(([url]) => url === MANIFEST);

const structureReads = () =>
    fetch.mock.calls.filter(([url]) => url === STRUCTURE);

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
const sign = () => document.querySelector('[data-board-live-target="paused"]');
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
            data-board-live-manifest-value="${MANIFEST}"
            data-board-live-structure-value="${STRUCTURE}"
            data-board-live-stale-value="${STALE_TEXT}">
        <p data-board-live-target="paused" role="status" data-message="Live updates paused" data-failed-message="${FAILED_TEXT}"></p>
        <div id="board" data-board-structure-digest="frame">
            <div class="lp-board__group" data-column="k">
                <article id="board-card-a" class="lp-board-card" data-card-id="a" data-card-digest="old"></article>
                <article id="board-card-b" class="lp-board-card" data-card-id="b" data-card-digest="old"></article>
            </div>
            <turbo-frame id="board-list"><div class="lp-board-list">
                <a id="board-row-a" class="lp-board-list__row" data-card-id="a" data-column-id="k" data-card-digest="old"></a>
                <a id="board-row-b" class="lp-board-list__row" data-card-id="b" data-column-id="k" data-card-digest="old"></a>
            </div></turbo-frame>
        </div>
    </div>`;
    dispatch = vi.spyOn(BoardLiveController.prototype, 'dispatch');
    answer = () => Promise.resolve(stream());
    vi.stubGlobal(
        'fetch',
        vi.fn((url, options) => answer(url, options)),
    );
    on.mockImplementation((types, handler, options = {}) => {
        if ([types].flat().includes('board.columns_changed')) {
            columnsChanged = handler;
            columnsOptions = options;
        } else {
            change = handler;
            reconnect = options.onReconnect;
        }
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

it('listens for card changes and for a reconnect', () => {
    expect(on).toHaveBeenCalledWith(
        ['board.card_changed', 'worker_run.card_warning_changed'],
        expect.any(Function),
        expect.objectContaining({ onReconnect: expect.any(Function) }),
    );
});

it('does not read the manifest when it first connects', async () => {
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetch).not.toHaveBeenCalled();
    expect(dispatch).not.toHaveBeenCalled();
});

it('reads the manifest after a reconnect and fetches a card whose digest changed', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'new', 'k', null],
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
    expect(dispatch).not.toHaveBeenCalled();
});

it('fetches a card the manifest has and the page does not', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['c', 'new', 'k', null],
                ['b', 'old', 'k', null],
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
                ['c', 'new', 'k', null],
                ['a', 'changed', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(150);

    expect(placements()).toEqual(['b', 'c', 'a']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('does nothing more when the page already shows every card', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(manifestReads()).toHaveLength(1);
    expect(placements()).toEqual([]);
    expect(dispatch).not.toHaveBeenCalled();
});

it('fetches a card that moved in its column, and not its unchanged neighbours', async () => {
    document.querySelector('.lp-board__group').insertAdjacentHTML(
        'beforeend',
        `<article id="board-card-c" class="lp-board-card" data-card-id="c" data-card-digest="old"></article>
            <article id="board-card-d" class="lp-board-card" data-card-id="d" data-card-digest="old"></article>`,
    );
    document.querySelector('.lp-board-list').insertAdjacentHTML(
        'beforeend',
        `<a class="lp-board-list__row" data-card-id="c" data-card-digest="old"></a>
            <a class="lp-board-list__row" data-card-id="d" data-card-digest="old"></a>`,
    );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['c', 'old', 'k', null],
                ['d', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['b']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('compares the order in each lane cell on its own', async () => {
    document.getElementById('board').insertAdjacentHTML(
        'beforeend',
        `<div class="lp-board-lane__cell" data-column="k" data-lane="epic">
            <article id="board-card-c" class="lp-board-card" data-card-id="c" data-card-digest="old"></article>
        </div>`,
    );
    row().insertAdjacentHTML(
        'afterend',
        '<a class="lp-board-list__row" data-card-id="c" data-card-digest="old"></a>',
    );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['c', 'old', 'k', 'epic'],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual([]);
});

it('fetches a card whose lane changed within its column while its cell kept its order', async () => {
    document.getElementById('board').innerHTML = `
        <div class="lp-board-lane__cell" data-column="k" data-lane="epic">
            <article id="board-card-a" class="lp-board-card" data-card-id="a" data-card-digest="old"></article>
        </div>
        <div class="lp-board-lane__cell" data-column="k" data-lane="other">
            <article id="board-card-b" class="lp-board-card" data-card-id="b" data-card-digest="old"></article>
        </div>`;
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', 'epic'],
                ['b', 'old', 'k', 'epic'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['b']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('fetches a card that the page shows in a plain column while the manifest puts it in a lane', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', 'other'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['b']);
    expect(dispatch).not.toHaveBeenCalled();
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
                ['epic', 'old', 'k', 'epic', 'head'],
                ['b', 'old', 'k', 'other'],
                ['a', 'old', 'k', 'epic'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['a']);
    expect(dispatch).not.toHaveBeenCalled();
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
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
                ['epic', 'old', 'k', 'epic', 'head'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['epic']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('never counts a lane head with a list row as an added or a removed card', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a class="lp-board-list__row" data-card-id="epic"></a>' +
                '<a class="lp-board-backlog" data-column="backlog"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
                ['epic', 'new', 'k', 'epic', 'head'],
                ['gone', 'new', 'backlog', 'gone', 'head'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual([]);
    expect(dispatch).not.toHaveBeenCalled();
});

const twoCards = (digestA = 'old') =>
    json({
        cards: [
            ['a', digestA, 'k', null],
            ['b', 'old', 'k', null],
        ],
        structure: 'frame',
    });

it('skips the list row checks while the list is not loaded', async () => {
    document.getElementById('board-list').remove();
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<section id="board-lane-epic"><header class="lp-board-lane__head" data-lane-digest="head"></header></section>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
                ['epic', 'old', 'k', 'epic', 'head'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(manifestReads()).toHaveLength(1);
    expect(placements()).toEqual([]);
});

it('fetches a card whose list row lags its face', async () => {
    card().dataset.cardDigest = 'new';
    answerManifest(twoCards('new'));
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['a']);
});

it('fetches a card whose face has no list row', async () => {
    document.getElementById('board-row-b').remove();
    answerManifest(twoCards());
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['b']);
});

it('catches up when the list frame renders, and not for another frame', async () => {
    answerManifest(twoCards());
    document.body.insertAdjacentHTML(
        'beforeend',
        '<turbo-frame id="card-drawer-frame"></turbo-frame>',
    );
    document
        .getElementById('card-drawer-frame')
        .dispatchEvent(
            new CustomEvent('turbo:frame-render', { bubbles: true }),
        );
    await vi.advanceTimersByTimeAsync(1000);
    expect(manifestReads()).toHaveLength(0);

    document
        .getElementById('board-list')
        .dispatchEvent(
            new CustomEvent('turbo:frame-render', { bubbles: true }),
        );
    await vi.advanceTimersByTimeAsync(1000);
    expect(manifestReads()).toHaveLength(1);
});

it('fetches a lane head that has no list row on the page', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
                ['epic', 'new', 'k', 'epic', 'head'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['epic']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('fetches a list row whose card the manifest no longer has, such as a deleted lane epic', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a id="board-row-epic" class="lp-board-list__row" data-card-id="epic" data-card-digest="old"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['epic']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('fetches a card whose face lost its digest when it left an epic lane', async () => {
    card().dataset.cardDigest = '';
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['a']);
});

it('fetches a lane head whose list row digest changed, and not one that kept it', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a class="lp-board-list__row" data-card-id="changed" data-card-digest="old"></a>' +
                '<a class="lp-board-list__row" data-card-id="kept" data-card-digest="old"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
                ['changed', 'new', 'k', 'changed', 'head'],
                ['kept', 'old', 'k', 'kept', 'head'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['changed']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('fetches a lane head in the Backlog whose head digest changed, and not one that kept it', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<section id="board-lane-changed"><header class="lp-board-lane__head" data-lane-digest="old"></header></section>' +
                '<section id="board-lane-kept"><header class="lp-board-lane__head" data-lane-digest="old"></header></section>' +
                '<a class="lp-board-backlog" data-column="backlog"></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
                ['changed', 'new', 'backlog', 'changed', 'new'],
                ['kept', 'old', 'backlog', 'kept', 'old'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['changed']);
    expect(dispatch).not.toHaveBeenCalled();
});

it('fetches the epic of a deck card whose card the board does not show', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<div class="lp-deck" data-lane="epic"><article id="board-deck-card-x" data-card-id="x"></article></div>',
        );
    document.dispatchEvent(
        new CustomEvent('board:placed', {
            detail: { cardId: 'x', removed: true },
        }),
    );
    await vi.advanceTimersByTimeAsync(150);

    expect(placements()).toEqual(['epic']);
});

it('fetches the epic whose deck a new Backlog card joins', async () => {
    document.dispatchEvent(
        new CustomEvent('board:placed', {
            detail: { cardId: 'new', removed: true, deckEpic: 'epic' },
        }),
    );
    await vi.advanceTimersByTimeAsync(150);

    expect(placements()).toEqual(['epic']);
});

it('fetches both epics when a Backlog card changes its epic', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<div class="lp-deck" data-lane="old"><article id="board-deck-card-x" data-card-id="x"></article></div>',
        );
    document.dispatchEvent(
        new CustomEvent('board:placed', {
            detail: { cardId: 'x', removed: true, deckEpic: 'new' },
        }),
    );
    await vi.advanceTimersByTimeAsync(150);

    expect(placements().sort()).toEqual(['new', 'old']);
});

it('fetches the epic of the deck a placed card left', async () => {
    document.dispatchEvent(
        new CustomEvent('board:placed', {
            detail: { cardId: 'x', leftDeck: 'epic' },
        }),
    );
    await vi.advanceTimersByTimeAsync(150);

    expect(placements()).toEqual(['epic']);
});

it('holds a deck card that is being dragged', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<div class="lp-deck" data-lane="epic"><article id="board-deck-card-x" data-card-id="x" class="lp-deck__card lp-board-card--dragging"></article></div>',
        );
    receive('x');
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual([]);
});

describe('a lane head that holds a deck card in a drag', () => {
    const laneWith = (deckCard) =>
        document
            .getElementById('board')
            .insertAdjacentHTML(
                'beforeend',
                `<section id="board-lane-epic"><header class="lp-board-lane__head"><div class="lp-deck" data-lane="epic">${deckCard}</div></header></section>`,
            );

    it('holds the placement of the epic while the drag runs, and places it after', async () => {
        laneWith(
            '<article id="board-deck-card-x" data-card-id="x" class="lp-deck__card lp-board-card--dragging"></article>',
        );
        receive('epic');
        await vi.advanceTimersByTimeAsync(1000);
        expect(placements()).toEqual([]);

        document
            .getElementById('board-deck-card-x')
            .classList.remove('lp-board-card--dragging');
        await vi.advanceTimersByTimeAsync(1000);
        expect(placements()).toEqual(['epic']);
    });

    it('holds the placement of the epic while the move of a deck card is pending', async () => {
        laneWith(
            '<article id="board-deck-card-x" data-card-id="x" class="lp-deck__card" aria-busy="true"></article>',
        );
        receive('epic');
        await vi.advanceTimersByTimeAsync(1000);

        expect(placements()).toEqual([]);
    });

    it('does not render a placement of the epic that returns during a drag', async () => {
        laneWith(
            '<article id="board-deck-card-x" data-card-id="x" class="lp-deck__card"></article>',
        );
        let reply;
        answer = () =>
            new Promise((resolve) => {
                reply = resolve;
            });
        receive('epic');
        await vi.advanceTimersByTimeAsync(150);
        expect(placements()).toEqual(['epic']);

        document
            .getElementById('board-deck-card-x')
            .classList.add('lp-board-card--dragging');
        reply(stream());
        await vi.advanceTimersByTimeAsync(0);

        expect(renderStreamMessage).not.toHaveBeenCalled();
    });
});

it('makes no reload on a reconnect after a live lane head placement', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<section id="board-lane-epic"><header class="lp-board-lane__head"></header></section>' +
                '<a id="board-row-epic" class="lp-board-list__row" data-card-id="epic" data-card-digest="old"></a>',
        );
    receive('epic');
    await vi.advanceTimersByTimeAsync(150);
    expect(placements()).toEqual(['epic']);
    // The lane head stream re-places the list row with its new digest.
    const lane = document.getElementById('board-lane-epic');
    document.getElementById('board-row-epic').dataset.cardDigest = 'new';
    lane.dispatchEvent(
        new CustomEvent('board:placed', {
            bubbles: true,
            detail: { cardId: 'epic' },
        }),
    );

    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
                ['epic', 'new', 'k', 'epic', 'head'],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['epic']);
    expect(manifestReads()).toHaveLength(1);
    expect(dispatch).not.toHaveBeenCalled();
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
                ['epic', 'old', 'k', 'epic', 'head'],
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
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
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
            terminalTotals: { done: 3 },
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['a']);
    expect(dispatch).not.toHaveBeenCalled();
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
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
            terminalTotals: { done: 4 },
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(manifestReads()).toHaveLength(1);
    expect(placements()).toEqual([]);
    expect(dispatch).not.toHaveBeenCalled();
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
                ['a', 'old', 'k', null],
                ['b', 'new', 'k', null],
            ],
            structure: 'frame',
            terminalTotals: { done: 3 },
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(placements()).toEqual(['b']);
});

it('sets the Backlog count from the manifest on a reconnect, with no placement', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a class="lp-board-backlog" data-column="bl"><span id="board-count-bl">3</span></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
            terminalTotals: {},
            backlogCount: 5,
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(document.getElementById('board-count-bl').textContent).toBe('5');
    expect(placements()).toEqual([]);
});

it('keeps the Backlog count of a manifest that has none', async () => {
    document
        .getElementById('board')
        .insertAdjacentHTML(
            'beforeend',
            '<a class="lp-board-backlog" data-column="bl"><span id="board-count-bl">3</span></a>',
        );
    answerManifest(
        json({
            cards: [
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
            terminalTotals: {},
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(1000);

    expect(document.getElementById('board-count-bl').textContent).toBe('3');
});

it('resyncs the structure when a history total changed and the board has no card', async () => {
    document.getElementById('board').innerHTML =
        '<a id="board-history-done" data-history-total="1"></a>';
    answerManifest(
        json({ cards: [], structure: 'frame', terminalTotals: { done: 0 } }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(structureReads()).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(300);
    expect(structureReads()).toHaveLength(1);
    expect(placements()).toEqual([]);
    expect(dispatch).not.toHaveBeenCalled();
});

it('resyncs the structure, and does not reload, when the structure changed', async () => {
    answerManifest(
        json({
            cards: [
                ['a', 'new', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'other',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(structureReads()).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(300);
    expect(structureReads()).toHaveLength(1);
    expect(dispatch).not.toHaveBeenCalled();
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
                ['a', 'old', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'renamed',
        }),
    );
    await vi.advanceTimersByTimeAsync(1000);

    expect(dispatch).not.toHaveBeenCalled();
});

it.each([
    [
        'a response that is not 2xx',
        () =>
            Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.resolve({}),
            }),
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
    [
        'a manifest card entry with no lane key',
        () =>
            Promise.resolve(
                json({ cards: [['a', 'old', 'k']], structure: 'frame' }),
            ),
    ],
    [
        'a manifest card entry whose lane key is not a string',
        () =>
            Promise.resolve(
                json({ cards: [['a', 'old', 'k', 7]], structure: 'frame' }),
            ),
    ],
])(
    'retries the catch-up after %s, and says live updates stopped after the last retry',
    async (label, response) => {
        answerManifest(response);
        reconnect();
        await vi.advanceTimersByTimeAsync(999);
        expect(manifestReads()).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(manifestReads()).toHaveLength(2);
        await vi.advanceTimersByTimeAsync(3000);
        expect(manifestReads()).toHaveLength(3);
        expect(sign().textContent).toBe('');

        await vi.advanceTimersByTimeAsync(9000);
        expect(manifestReads()).toHaveLength(4);
        expect(sign().textContent).toBe(FAILED_TEXT);

        await vi.advanceTimersByTimeAsync(60000);
        expect(manifestReads()).toHaveLength(4);
        expect(placements()).toEqual([]);
        expect(dispatch).not.toHaveBeenCalled();
    },
);

it.each([403, 404, 401])(
    'stops the catch-up at once on a %s, and says live updates stopped',
    async (code) => {
        answerManifest({ ok: false, status: code, json: () => ({}) });
        reconnect();
        await vi.advanceTimersByTimeAsync(0);
        expect(sign().textContent).toBe(FAILED_TEXT);

        await vi.advanceTimersByTimeAsync(60000);
        expect(manifestReads()).toHaveLength(1);
        expect(dispatch).not.toHaveBeenCalled();
    },
);

it('clears the message on the next successful catch-up', async () => {
    answerManifest({ ok: false, status: 404, json: () => ({}) });
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(sign().textContent).toBe(FAILED_TEXT);

    answerManifest(
        json({
            cards: [
                ['a', 'new', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(150);
    expect(sign().textContent).toBe('');
    expect(placements()).toEqual(['a']);
});

it('cancels a waiting catch-up retry when a new reconnect starts', async () => {
    answerManifest({ ok: false, status: 500, json: () => ({}) });
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    answerManifest(json({ cards: [], structure: 'frame' }));
    reconnect();
    await vi.advanceTimersByTimeAsync(60000);

    expect(manifestReads()).toHaveLength(2);
    expect(sign().textContent).toBe('');
});

it('clears a waiting catch-up retry when it disconnects', async () => {
    answerManifest({ ok: false, status: 500, json: () => ({}) });
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(vi.getTimerCount()).toBe(1);

    document.getElementById('wrapper').removeAttribute('data-controller');
    await vi.advanceTimersByTimeAsync(0);
    expect(vi.getTimerCount()).toBe(0);
});

it('shows the paused sign before the failed sign, and the failed sign while live', async () => {
    answerManifest({ ok: false, status: 403, json: () => ({}) });
    reconnect();
    await vi.advanceTimersByTimeAsync(0);
    expect(sign().textContent).toBe(FAILED_TEXT);

    setStatus('paused');
    expect(sign().textContent).toBe('Live updates paused');
    setStatus('live');
    expect(sign().textContent).toBe(FAILED_TEXT);
});

it('gives up on a stalled manifest read and retries it', async () => {
    answerManifest(
        (options) =>
            new Promise((resolve, reject) => {
                options.signal.addEventListener('abort', () =>
                    reject(new DOMException('Aborted', 'AbortError')),
                );
            }),
    );
    reconnect();
    await vi.advanceTimersByTimeAsync(10000 + 999);
    expect(manifestReads()).toHaveLength(1);

    await vi.advanceTimersByTimeAsync(1);
    expect(manifestReads()).toHaveLength(2);
    expect(dispatch).not.toHaveBeenCalled();
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
    expect(dispatch).not.toHaveBeenCalled();

    finishes[1](
        json({
            cards: [
                ['a', 'new', 'k', null],
                ['b', 'old', 'k', null],
            ],
            structure: 'frame',
        }),
    );
    await vi.advanceTimersByTimeAsync(150);
    expect(placements()).toEqual(['a']);
    expect(dispatch).not.toHaveBeenCalled();
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
    expect(dispatch).not.toHaveBeenCalled();
    expect(vi.getTimerCount()).toBe(0);
});

it('listens for card changes and for run warning changes', () => {
    expect(on).toHaveBeenCalledWith(
        ['board.card_changed', 'worker_run.card_warning_changed'],
        expect.any(Function),
        expect.any(Object),
    );
});

it('fetches the placement of the card a run warning names, and marks it when its face changed', async () => {
    change({
        type: 'worker_run.card_warning_changed',
        cardId: 'a',
        local: false,
        own: false,
    });
    await vi.advanceTimersByTimeAsync(150);
    expect(fetch.mock.calls.map(([url]) => url)).toEqual([
        '/projects/p/board/cards/a/placement',
    ]);
    placed('a', 'new');
    expect(card().classList.contains('lp-board-card--flash')).toBe(true);
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

    for (const [element, className] of [
        [card(), 'lp-board-card'],
        [row(), 'lp-board-list__row'],
    ]) {
        expect(element.className).toBe(className);
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

describe('a structure resync', () => {
    const current = {
        cards: [
            ['a', 'new', 'k', null],
            ['b', 'old', 'k', null],
        ],
        structure: 'frame',
    };

    function answerRoutes({
        structure = () => stream('<s></s>'),
        manifest = () => json(current),
    } = {}) {
        answer = (url, options) => {
            if (url === STRUCTURE) {
                return Promise.resolve().then(() => structure(options));
            }
            if (url === MANIFEST) {
                return Promise.resolve().then(() => manifest(options));
            }

            return Promise.resolve(stream());
        };
    }

    const structureChanged = () =>
        document.dispatchEvent(new CustomEvent('board:structure-changed'));

    beforeEach(() => {
        // Turbo renders a stream element on a later frame, not in the call.
        renderStreamMessage.mockImplementation(() => {
            setTimeout(structureChanged, 16);
        });
    });

    const signal = () => columnsChanged({ type: 'board.columns_changed' });
    const board = () => document.getElementById('board');

    it('retries the resync when the structure renders and the manifest read fails', async () => {
        answerRoutes({ manifest: () => failure(500) });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        await vi.advanceTimersByTimeAsync(16);

        expect(renderStreamMessage).toHaveBeenCalledWith('<s></s>');
        expect(manifestReads()).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(999);
        expect(structureReads()).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(structureReads()).toHaveLength(2);
        expect(placements()).toEqual([]);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('stops the resync at once when the manifest read after the render answers 403', async () => {
        answerRoutes({ manifest: () => failure(403) });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        await vi.advanceTimersByTimeAsync(16);
        expect(sign().textContent).toBe(FAILED_TEXT);

        await vi.advanceTimersByTimeAsync(60000);
        expect(structureReads()).toHaveLength(1);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('resyncs once when the history total stays stale on a board with no card', async () => {
        board().innerHTML =
            '<a id="board-history-done" data-history-total="1"></a>';
        answerRoutes({
            manifest: () =>
                json({
                    cards: [],
                    structure: 'frame',
                    terminalTotals: { done: 0 },
                }),
        });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        await vi.advanceTimersByTimeAsync(16);
        expect(manifestReads()).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(60000);
        expect(structureReads()).toHaveLength(1);
        expect(manifestReads()).toHaveLength(1);
        expect(sign().textContent).toBe('');
    });

    it('runs the card pass only after the structure has rendered', async () => {
        answerRoutes();
        renderStreamMessage.mockImplementation(() => {});
        signal();
        await vi.advanceTimersByTimeAsync(300);
        await vi.advanceTimersByTimeAsync(1000);
        expect(manifestReads()).toHaveLength(1);
        expect(placements()).toEqual([]);

        structureChanged();
        await vi.advanceTimersByTimeAsync(150);
        expect(placements()).toEqual(['a']);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('retries the resync when the structure never renders', async () => {
        answerRoutes();
        renderStreamMessage.mockImplementation(() => {});
        signal();
        await vi.advanceTimersByTimeAsync(300);
        await vi.advanceTimersByTimeAsync(5000 + 999);
        expect(structureReads()).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(structureReads()).toHaveLength(2);
        expect(placements()).toEqual([]);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('listens for a column change', () => {
        expect(on).toHaveBeenCalledWith(
            'board.columns_changed',
            expect.any(Function),
            expect.any(Object),
        );
    });

    it('fetches the structure once, 300 ms after a column change', async () => {
        answerRoutes();
        signal();
        await vi.advanceTimersByTimeAsync(299);
        expect(structureReads()).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(structureReads()).toHaveLength(1);
        const [, options] = structureReads()[0];
        expect(options.headers.Accept).toBe('text/vnd.turbo-stream.html');
        expect(options.credentials).toBe('same-origin');
        expect(options.signal).toBeInstanceOf(AbortSignal);
    });

    it('fetches the structure once for a burst of column changes', async () => {
        answerRoutes();
        signal();
        await vi.advanceTimersByTimeAsync(200);
        signal();
        await vi.advanceTimersByTimeAsync(200);
        signal();
        await vi.advanceTimersByTimeAsync(299);
        expect(structureReads()).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1000);
        expect(structureReads()).toHaveLength(1);
    });

    it('resyncs a steady stream of column changes at least once per max wait', async () => {
        answerRoutes();
        for (let elapsed = 0; elapsed < 2000; elapsed += 100) {
            signal();
            await vi.advanceTimersByTimeAsync(100);
        }
        expect(structureReads()).toHaveLength(1);
    });

    it('renders the structure, then reads the manifest and places each changed card', async () => {
        answerRoutes();
        signal();
        await vi.advanceTimersByTimeAsync(300);
        await vi.advanceTimersByTimeAsync(16 + 150);

        expect(renderStreamMessage).toHaveBeenCalledWith('<s></s>');
        expect(manifestReads()).toHaveLength(1);
        const manifestCall = fetch.mock.calls.findIndex(
            ([url]) => url === MANIFEST,
        );
        expect(renderStreamMessage.mock.invocationCallOrder[0]).toBeLessThan(
            fetch.mock.invocationCallOrder[manifestCall],
        );
        expect(placements()).toEqual(['a']);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('places the cards even when the manifest structure differs from the page', async () => {
        answerRoutes();
        board().dataset.boardStructureDigest = 'older';
        signal();
        await vi.advanceTimersByTimeAsync(300);
        await vi.advanceTimersByTimeAsync(3000);

        expect(placements()).toEqual(['a']);
        expect(structureReads()).toHaveLength(1);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('waits while a drag runs, and resyncs once it ends', async () => {
        answerRoutes();
        board().classList.add('lp-board--dragging');
        signal();
        await vi.advanceTimersByTimeAsync(3000);
        expect(structureReads()).toHaveLength(0);

        board().classList.remove('lp-board--dragging');
        await vi.advanceTimersByTimeAsync(300);
        expect(structureReads()).toHaveLength(1);
    });

    it('waits while a card move is pending or a dialog is open', async () => {
        answerRoutes();
        card().setAttribute('data-board-drag-target', 'card');
        card().setAttribute('aria-busy', 'true');
        const dialog = document.createElement('dialog');
        dialog.setAttribute('open', '');
        board().append(dialog);
        signal();
        await vi.advanceTimersByTimeAsync(3000);
        card().removeAttribute('aria-busy');
        await vi.advanceTimersByTimeAsync(3000);
        expect(structureReads()).toHaveLength(0);

        dialog.removeAttribute('open');
        await vi.advanceTimersByTimeAsync(300);
        expect(structureReads()).toHaveLength(1);
    });

    it('does not render a structure that returns during a drag, and resyncs after the drag', async () => {
        let finish;
        answerRoutes({
            structure: () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        board().classList.add('lp-board--dragging');
        finish(stream('<s></s>'));
        await vi.advanceTimersByTimeAsync(3000);
        expect(renderStreamMessage).not.toHaveBeenCalled();
        expect(manifestReads()).toHaveLength(0);

        answerRoutes();
        board().classList.remove('lp-board--dragging');
        await vi.advanceTimersByTimeAsync(300);
        expect(structureReads()).toHaveLength(2);
        expect(renderStreamMessage).toHaveBeenCalledOnce();
    });

    it('runs exactly one more resync for the changes that arrive while one runs', async () => {
        const finishes = [];
        answerRoutes({
            structure: () =>
                new Promise((resolve) => {
                    finishes.push(resolve);
                }),
        });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        signal();
        signal();
        await vi.advanceTimersByTimeAsync(3000);
        expect(structureReads()).toHaveLength(1);

        finishes[0](stream('<first></first>'));
        await vi.advanceTimersByTimeAsync(16);
        await vi.advanceTimersByTimeAsync(299);
        expect(structureReads()).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(structureReads()).toHaveLength(2);

        finishes[1](stream('<second></second>'));
        await vi.advanceTimersByTimeAsync(3000);
        expect(structureReads()).toHaveLength(2);
        expect(renderStreamMessage.mock.calls.map(([html]) => html)).toEqual(
            expect.arrayContaining(['<first></first>', '<second></second>']),
        );
    });

    it.each([
        ['a response that is not 2xx', () => failure(500)],
        ['a response that is not a stream', () => failure(200)],
        ['a network error', () => Promise.reject(new TypeError('offline'))],
    ])(
        'retries the resync after %s, and says live updates stopped after the last retry',
        async (label, structure) => {
            answerRoutes({ structure });
            signal();
            await vi.advanceTimersByTimeAsync(300);
            expect(structureReads()).toHaveLength(1);
            await vi.advanceTimersByTimeAsync(999);
            expect(structureReads()).toHaveLength(1);
            await vi.advanceTimersByTimeAsync(1);
            expect(structureReads()).toHaveLength(2);
            await vi.advanceTimersByTimeAsync(3000);
            expect(structureReads()).toHaveLength(3);
            expect(sign().textContent).toBe('');

            await vi.advanceTimersByTimeAsync(9000);
            expect(structureReads()).toHaveLength(4);
            expect(sign().textContent).toBe(FAILED_TEXT);

            await vi.advanceTimersByTimeAsync(60000);
            expect(structureReads()).toHaveLength(4);
            expect(renderStreamMessage).not.toHaveBeenCalled();
            expect(manifestReads()).toHaveLength(0);
            expect(dispatch).not.toHaveBeenCalled();
        },
    );

    it.each([403, 404])(
        'stops the resync at once on a %s structure read',
        async (code) => {
            answerRoutes({ structure: () => failure(code) });
            signal();
            await vi.advanceTimersByTimeAsync(300);
            expect(sign().textContent).toBe(FAILED_TEXT);

            await vi.advanceTimersByTimeAsync(60000);
            expect(structureReads()).toHaveLength(1);
        },
    );

    it('clears the message on the next successful resync', async () => {
        answerRoutes({ structure: () => failure(404) });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        expect(sign().textContent).toBe(FAILED_TEXT);

        answerRoutes();
        signal();
        await vi.advanceTimersByTimeAsync(300 + 16 + 150);
        expect(sign().textContent).toBe('');
        expect(placements()).toEqual(['a']);
    });

    it('counts the attempts afresh after a successful resync', async () => {
        answerRoutes({ structure: () => failure(500) });
        signal();
        await vi.advanceTimersByTimeAsync(300 + 1000 + 3000);
        expect(structureReads()).toHaveLength(3);

        answerRoutes();
        await vi.advanceTimersByTimeAsync(9000 + 16 + 150);
        expect(structureReads()).toHaveLength(4);

        answerRoutes({ structure: () => failure(500) });
        signal();
        await vi.advanceTimersByTimeAsync(300 + 1000 + 3000);
        expect(structureReads()).toHaveLength(7);
        expect(sign().textContent).toBe('');
    });

    it('lets a column change that arrives during a retry wait take over the retry', async () => {
        answerRoutes({ structure: () => failure(500) });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        expect(structureReads()).toHaveLength(1);

        answerRoutes();
        signal();
        await vi.advanceTimersByTimeAsync(300);
        expect(structureReads()).toHaveLength(2);
        await vi.advanceTimersByTimeAsync(60000);
        expect(structureReads()).toHaveLength(2);
    });

    it('cancels a waiting catch-up retry when a resync starts', async () => {
        answerRoutes({ manifest: () => failure(500) });
        reconnect();
        await vi.advanceTimersByTimeAsync(0);
        expect(manifestReads()).toHaveLength(1);

        answerRoutes();
        signal();
        await vi.advanceTimersByTimeAsync(300 + 16 + 150);
        expect(manifestReads()).toHaveLength(2);
        await vi.advanceTimersByTimeAsync(60000);
        expect(manifestReads()).toHaveLength(2);
        expect(sign().textContent).toBe('');
    });

    it('leaves a change that arrives during a failed read to the retry', async () => {
        let finish;
        answerRoutes({
            structure: () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        signal();
        finish(failure(500));
        await vi.advanceTimersByTimeAsync(999);
        expect(structureReads()).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(structureReads()).toHaveLength(2);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('gives up on a stalled structure read and retries it', async () => {
        answerRoutes({
            structure: (options) =>
                new Promise((resolve, reject) => {
                    options.signal.addEventListener('abort', () =>
                        reject(new DOMException('Aborted', 'AbortError')),
                    );
                }),
        });
        signal();
        await vi.advanceTimersByTimeAsync(300 + 10000 + 999);
        expect(structureReads()).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(structureReads()).toHaveLength(2);
        expect(dispatch).not.toHaveBeenCalled();
    });

    it('aborts a structure read when it disconnects, and neither renders nor retries', async () => {
        let readSignal;
        answerRoutes({
            structure: (options) =>
                new Promise((resolve, reject) => {
                    readSignal = options.signal;
                    options.signal.addEventListener('abort', () =>
                        reject(new DOMException('Aborted', 'AbortError')),
                    );
                }),
        });
        signal();
        await vi.advanceTimersByTimeAsync(300);
        signal();

        document.getElementById('wrapper').removeAttribute('data-controller');
        await vi.advanceTimersByTimeAsync(0);

        expect(readSignal.aborted).toBe(true);
        expect(dispatch).not.toHaveBeenCalled();
        expect(renderStreamMessage).not.toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('drops a waiting resync when it disconnects', async () => {
        answerRoutes();
        signal();
        document.getElementById('wrapper').removeAttribute('data-controller');
        await vi.advanceTimersByTimeAsync(3000);

        expect(structureReads()).toHaveLength(0);
    });

    it('marks the element while the column subscription is open', async () => {
        const wrapper = document.getElementById('wrapper');
        const marked = () => wrapper.hasAttribute('data-board-live-connected');
        expect(marked()).toBe(false);
        columnsOptions.onOpen();
        expect(marked()).toBe(true);
        columnsOptions.onError();
        expect(marked()).toBe(false);
        columnsOptions.onOpen();

        wrapper.removeAttribute('data-controller');
        await vi.advanceTimersByTimeAsync(0);
        expect(marked()).toBe(false);
    });
});

function tracked(promise) {
    const state = { settled: false };
    promise.then(() => (state.settled = true));

    return state;
}

const cardChange = (cardId) => ({
    type: 'board.card_changed',
    cardId,
    change: 'updated',
    local: false,
    own: false,
});

describe('the promise of a change', () => {
    it('answers none for a change with no card', () => {
        expect(change({ ...cardChange(''), cardId: undefined })).toBe(
            undefined,
        );
        expect(change(cardChange(''))).toBeUndefined();
    });

    it('settles when its card is placed, and not before', async () => {
        const result = tracked(change(cardChange('a')));
        await vi.advanceTimersByTimeAsync(150);
        expect(fetch).toHaveBeenCalledOnce();
        expect(result.settled).toBe(false);

        placed('b', 'new');
        await vi.advanceTimersByTimeAsync(0);
        expect(result.settled).toBe(false);

        placed('a', 'new');
        await vi.advanceTimersByTimeAsync(0);
        expect(result.settled).toBe(true);
    });

    it('keeps waiting for the next fetch when a change arrives during a fetch', async () => {
        let finish;
        answer = () =>
            new Promise((resolve) => {
                finish = resolve;
            });
        const first = tracked(change(cardChange('a')));
        await vi.advanceTimersByTimeAsync(150);
        const second = tracked(change(cardChange('a')));
        finish(stream());
        await vi.advanceTimersByTimeAsync(0);
        placed('a', 'new');
        await vi.advanceTimersByTimeAsync(0);
        expect(first.settled).toBe(false);
        expect(second.settled).toBe(false);

        await vi.advanceTimersByTimeAsync(150);
        expect(fetch).toHaveBeenCalledTimes(2);
        finish(stream());
        await vi.advanceTimersByTimeAsync(0);
        placed('a', 'newer');
        await vi.advanceTimersByTimeAsync(0);
        expect(first.settled).toBe(true);
        expect(second.settled).toBe(true);
    });

    it('waits through a missed placement, and settles on the retry that places the card', async () => {
        const result = tracked(change(cardChange('a')));
        await vi.advanceTimersByTimeAsync(150);
        missed('a');
        await vi.advanceTimersByTimeAsync(1000);
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(result.settled).toBe(false);

        placed('a', 'new');
        await vi.advanceTimersByTimeAsync(0);
        expect(result.settled).toBe(true);
    });

    it('settles when the card is marked stale', async () => {
        answer = () => Promise.resolve(failure(503));
        const result = tracked(change(cardChange('a')));
        await vi.advanceTimersByTimeAsync(150 + 1000 + 3000);
        expect(result.settled).toBe(false);

        await vi.advanceTimersByTimeAsync(9000);
        expect(isStale()).toBe(true);
        expect(result.settled).toBe(true);
    });

    it('settles when it disconnects', async () => {
        answer = () => new Promise(() => {});
        const result = tracked(change(cardChange('a')));
        await vi.advanceTimersByTimeAsync(150);
        expect(result.settled).toBe(false);

        document.getElementById('wrapper').remove();
        await vi.advanceTimersByTimeAsync(0);
        expect(result.settled).toBe(true);
    });
});
