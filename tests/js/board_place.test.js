/** @vitest-environment jsdom */
import { StreamActions } from '@hotwired/turbo';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { placeCard } from '../../assets/lib/board_place.js';

const BACKLOG = 'col-backlog';
const NEXT = 'col-next';

function card(id, column, title = id, digest = 'aaa') {
    return `<article class="lp-board-card" id="board-card-${id}" data-card-id="${id}" data-card-digest="${digest}"><a class="lp-board-card__title">${title}</a></article>`;
}

function row(id, column, title = id) {
    return `<a class="lp-board-list__row" id="board-row-${id}" data-card-id="${id}" data-column-id="${column}">${title}</a>`;
}

function renderBoard() {
    document.body.innerHTML = `<div id="board">
        <section id="board-column-${BACKLOG}"><span id="board-count-${BACKLOG}">2</span>
            <div class="lp-board__group" id="board-group-${BACKLOG}">${card('a', BACKLOG)}${card('b', BACKLOG)}</div>
        </section>
        <section id="board-column-${NEXT}"><span id="board-count-${NEXT}">1</span>
            <div class="lp-board__group" id="board-group-${NEXT}">${card('c', NEXT)}</div>
            <a id="board-history-${NEXT}" data-history-total="1">See the one finished card</a>
        </section>
        <div class="lp-board-list">
            <div class="lp-board-list__header"></div>
            ${row('a', BACKLOG)}${row('b', BACKLOG)}${row('c', NEXT)}
        </div>
    </div>`;
}

function stream({
    id,
    column,
    after = '',
    rowAfter = '',
    counts,
    history = {},
    historyTotals = {},
    title = id,
    removed = false,
    lane = null,
    laneEpic = false,
    laneCounts = null,
}) {
    const holder = document.createElement('div');
    const lanes =
        (laneCounts
            ? ` data-lane-counts='${JSON.stringify(laneCounts)}'`
            : '') +
        (laneEpic ? ' data-lane-epic="1"' : '') +
        (lane ? ` data-lane="${lane}"` : '');
    const placement =
        (removed
            ? 'data-removed="1"'
            : `data-column-id="${column}" data-after="${after}" data-row-after="${rowAfter}"`) +
        lanes;
    const body = removed
        ? ''
        : card(id, column, title, 'bbb') + row(id, column, title);
    holder.innerHTML = `<turbo-stream action="board-place" target="board-card-${id}" data-counts='${JSON.stringify(counts)}' data-history='${JSON.stringify(history)}' data-history-totals='${JSON.stringify(historyTotals)}' ${placement}><template>${body}</template></turbo-stream>`;

    return holder.firstElementChild;
}

const order = (selector) =>
    [...document.querySelectorAll(selector)].map((node) => node.dataset.cardId);

beforeEach(renderBoard);

afterEach(() => {
    document.body.innerHTML = '';
});

describe('board-place', () => {
    it('registers itself as a Turbo stream action', () => {
        expect(typeof StreamActions['board-place']).toBe('function');
    });

    it('moves a card to another column after the card it follows, and morphs it', () => {
        const original = document.getElementById('board-card-a');
        const placed = vi.fn();
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            stream({
                id: 'a',
                column: NEXT,
                after: 'c',
                rowAfter: 'c',
                title: 'Renamed',
                counts: { [BACKLOG]: 1, [NEXT]: 2 },
            }),
        );

        expect(order(`#board-group-${NEXT} .lp-board-card`)).toEqual([
            'c',
            'a',
        ]);
        expect(order(`#board-group-${BACKLOG} .lp-board-card`)).toEqual(['b']);
        expect(order('.lp-board-list__row')).toEqual(['b', 'c', 'a']);
        expect(document.getElementById('board-card-a')).toBe(original);
        expect(original.dataset.cardDigest).toBe('bbb');
        expect(original.textContent).toBe('Renamed');
        expect(document.getElementById('board-row-a').dataset.columnId).toBe(
            NEXT,
        );
        expect(
            document.getElementById(`board-count-${BACKLOG}`).textContent,
        ).toBe('1');
        expect(document.getElementById(`board-count-${NEXT}`).textContent).toBe(
            '2',
        );
        expect(placed).toHaveBeenCalledOnce();
        expect(placed.mock.calls[0][0].target).toBe(original);
        expect(placed.mock.calls[0][0].detail).toEqual({ cardId: 'a' });
    });

    it('puts a card first in its column, and its row straight after the list header', () => {
        placeCard(
            stream({
                id: 'b',
                column: BACKLOG,
                counts: { [BACKLOG]: 2, [NEXT]: 1 },
            }),
        );

        expect(order(`#board-group-${BACKLOG} .lp-board-card`)).toEqual([
            'b',
            'a',
        ]);
        expect(order('.lp-board-list__row')).toEqual(['b', 'a', 'c']);
    });

    it('inserts a card the page does not have yet', () => {
        placeCard(
            stream({
                id: 'd',
                column: NEXT,
                rowAfter: 'b',
                counts: { [BACKLOG]: 2, [NEXT]: 2 },
            }),
        );

        expect(order(`#board-group-${NEXT} .lp-board-card`)).toEqual([
            'd',
            'c',
        ]);
        expect(order('.lp-board-list__row')).toEqual(['a', 'b', 'd', 'c']);
        expect(document.getElementById(`board-count-${NEXT}`).textContent).toBe(
            '2',
        );
    });

    it('removes a card and its row, and updates the counts', () => {
        const placed = vi.fn();
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            stream({
                id: 'a',
                removed: true,
                counts: { [BACKLOG]: 1, [NEXT]: 1 },
            }),
        );

        expect(document.getElementById('board-card-a')).toBeNull();
        expect(document.getElementById('board-row-a')).toBeNull();
        expect(
            document.getElementById(`board-count-${BACKLOG}`).textContent,
        ).toBe('1');
        expect(placed.mock.calls[0][0].detail).toEqual({
            cardId: 'a',
            removed: true,
        });
    });

    it('updates the history link of a terminal column when a card enters it', () => {
        placeCard(
            stream({
                id: 'a',
                column: NEXT,
                after: 'c',
                rowAfter: 'c',
                counts: { [BACKLOG]: 1, [NEXT]: 2 },
                history: { [NEXT]: 'See all 2 finished cards' },
                historyTotals: { [NEXT]: 2 },
            }),
        );

        const link = document.getElementById(`board-history-${NEXT}`);
        expect(link.textContent).toBe('See all 2 finished cards');
        expect(link.dataset.historyTotal).toBe('2');
    });

    it('updates the history link when a card leaves the board', () => {
        placeCard(
            stream({
                id: 'c',
                removed: true,
                counts: { [BACKLOG]: 2, [NEXT]: 0 },
                history: { [NEXT]: 'No card is finished yet' },
                historyTotals: { [NEXT]: 0 },
            }),
        );

        const link = document.getElementById(`board-history-${NEXT}`);
        expect(link.textContent).toBe('No card is finished yet');
        expect(link.dataset.historyTotal).toBe('0');
    });

    it('changes nothing and reports a miss when the column is not on the page', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(
            stream({
                id: 'a',
                column: 'col-unknown',
                counts: { [BACKLOG]: 1 },
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
        expect(missed.mock.calls[0][0].detail).toEqual({ cardId: 'a' });
    });

    it('changes nothing and reports a miss when the card it follows sits in another column on the page', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, {
            once: true,
        });
        const before = document.body.innerHTML;

        placeCard(
            stream({
                id: 'd',
                column: NEXT,
                after: 'b',
                rowAfter: 'b',
                counts: { [BACKLOG]: 2, [NEXT]: 2 },
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });

    it('changes nothing and reports a miss when the card it follows is not on the page', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(
            stream({
                id: 'a',
                column: NEXT,
                after: 'unknown',
                rowAfter: 'c',
                counts: { [BACKLOG]: 1, [NEXT]: 2 },
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });
});

const EPIC = '0192aaaa-0000-7000-8000-000000000001';

function cell(lane, column, count, cards) {
    return `<section class="lp-board__column lp-board-lane__column" data-column-id="${column}">
        <header class="lp-board__column-head"><span class="lp-board__column-count">${count}</span></header>
        <div class="lp-board-lane__cell" data-column="${column}" data-lane="${lane}">${cards}</div>
    </section>`;
}

function renderLanesBoard() {
    document.body.innerHTML = `<div id="board">
        <section class="lp-board-lane lp-board-lane--epic" data-lane="${EPIC}">
            <header class="lp-board-lane__head">Epic</header>
            <div class="lp-board-lane__cells">
                ${cell(EPIC, BACKLOG, 1, card('x', BACKLOG))}${cell(EPIC, NEXT, 0, '')}
            </div>
        </section>
        <section class="lp-board-lane lp-board-lane--other" data-lane="other">
            <div class="lp-board-lane__cells">
                ${cell('other', BACKLOG, 2, card('a', BACKLOG) + card('b', BACKLOG))}${cell('other', NEXT, 1, card('c', NEXT))}
            </div>
        </section>
        <div class="lp-board-list">
            <div class="lp-board-list__header"></div>
            ${row('a', BACKLOG)}${row('x', BACKLOG)}${row('b', BACKLOG)}${row('c', NEXT)}
        </div>
    </div>`;
}

const inCell = (lane, column) =>
    order(
        `.lp-board-lane__cell[data-lane="${lane}"][data-column="${column}"] .lp-board-card`,
    );

const countOf = (lane, column) =>
    document
        .querySelector(
            `.lp-board-lane__cell[data-lane="${lane}"][data-column="${column}"]`,
        )
        .parentElement.querySelector('.lp-board__column-count').textContent;

describe('board-place on a board with lanes', () => {
    beforeEach(renderLanesBoard);

    it('places a card in its lane cell after the card it follows there, and writes every cell count', () => {
        const placed = vi.fn();
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            stream({
                id: 'b',
                column: BACKLOG,
                after: 'x',
                rowAfter: 'x',
                lane: EPIC,
                counts: { [BACKLOG]: 3, [NEXT]: 1 },
                laneCounts: {
                    [EPIC]: { [BACKLOG]: 2, [NEXT]: 0 },
                    other: { [BACKLOG]: 1, [NEXT]: 1 },
                },
            }),
        );

        expect(inCell(EPIC, BACKLOG)).toEqual(['x', 'b']);
        expect(inCell('other', BACKLOG)).toEqual(['a']);
        expect(order('.lp-board-list__row')).toEqual(['a', 'x', 'b', 'c']);
        expect(countOf(EPIC, BACKLOG)).toBe('2');
        expect(countOf('other', BACKLOG)).toBe('1');
        expect(countOf('other', NEXT)).toBe('1');
        expect(placed).toHaveBeenCalledOnce();
    });

    it('moves a card to another lane and column, first in its cell', () => {
        placeCard(
            stream({
                id: 'a',
                column: NEXT,
                rowAfter: 'b',
                lane: EPIC,
                counts: { [BACKLOG]: 2, [NEXT]: 2 },
                laneCounts: {
                    [EPIC]: { [BACKLOG]: 1, [NEXT]: 1 },
                    other: { [BACKLOG]: 1, [NEXT]: 1 },
                },
            }),
        );

        expect(inCell(EPIC, NEXT)).toEqual(['a']);
        expect(inCell('other', BACKLOG)).toEqual(['b']);
        expect(countOf(EPIC, NEXT)).toBe('1');
        expect(countOf('other', BACKLOG)).toBe('1');
    });

    it('changes nothing and reports a miss when the card it follows sits in another lane cell', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(
            stream({
                id: 'c',
                column: BACKLOG,
                after: 'a',
                rowAfter: 'b',
                lane: EPIC,
                counts: { [BACKLOG]: 4, [NEXT]: 0 },
                laneCounts: {
                    [EPIC]: { [BACKLOG]: 2, [NEXT]: 0 },
                    other: { [BACKLOG]: 2, [NEXT]: 0 },
                },
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });

    it('changes nothing and reports a miss for the first lane epic on a page drawn with no lanes', () => {
        renderBoard();
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(
            stream({
                id: 'c',
                column: NEXT,
                rowAfter: 'b',
                laneEpic: true,
                counts: { [BACKLOG]: 3, [NEXT]: 1 },
                laneCounts: {
                    c: { [BACKLOG]: 0, [NEXT]: 0 },
                    other: { [BACKLOG]: 2, [NEXT]: 0 },
                },
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
        expect(missed.mock.calls[0][0].detail).toEqual({ cardId: 'c' });
    });

    it('changes nothing and reports a miss for a card the page shows as a lane head', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(
            stream({
                id: EPIC,
                column: NEXT,
                after: 'c',
                rowAfter: 'c',
                lane: 'other',
                counts: { [BACKLOG]: 3, [NEXT]: 2 },
                laneCounts: { other: { [BACKLOG]: 3, [NEXT]: 2 } },
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });

    it('reports a miss when a lane head leaves the board', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });

        placeCard(
            stream({
                id: EPIC,
                removed: true,
                counts: { [BACKLOG]: 3, [NEXT]: 1 },
                laneCounts: { other: { [BACKLOG]: 3, [NEXT]: 1 } },
            }),
        );

        expect(missed).toHaveBeenCalledOnce();
    });

    it('removes a card and writes every cell count', () => {
        placeCard(
            stream({
                id: 'x',
                removed: true,
                counts: { [BACKLOG]: 2, [NEXT]: 1 },
                laneCounts: {
                    [EPIC]: { [BACKLOG]: 0, [NEXT]: 0 },
                    other: { [BACKLOG]: 2, [NEXT]: 1 },
                },
            }),
        );

        expect(document.getElementById('board-card-x')).toBeNull();
        expect(document.getElementById('board-row-x')).toBeNull();
        expect(countOf(EPIC, BACKLOG)).toBe('0');
    });

    it('reports a miss when the page has lanes and the stream has none', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });

        placeCard(
            stream({
                id: 'a',
                column: NEXT,
                after: 'c',
                rowAfter: 'c',
                counts: { [BACKLOG]: 2, [NEXT]: 2 },
            }),
        );

        expect(missed).toHaveBeenCalledOnce();
    });
});
