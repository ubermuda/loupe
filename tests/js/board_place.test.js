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
            <a id="board-history-${NEXT}">See the one finished card</a>
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
    title = id,
    removed = false,
}) {
    const holder = document.createElement('div');
    const placement = removed
        ? 'data-removed="1"'
        : `data-column-id="${column}" data-after="${after}" data-row-after="${rowAfter}"`;
    const body = removed
        ? ''
        : card(id, column, title, 'bbb') + row(id, column, title);
    holder.innerHTML = `<turbo-stream action="board-place" target="board-card-${id}" data-counts='${JSON.stringify(counts)}' data-history='${JSON.stringify(history)}' ${placement}><template>${body}</template></turbo-stream>`;

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
            }),
        );

        expect(
            document.getElementById(`board-history-${NEXT}`).textContent,
        ).toBe('See all 2 finished cards');
    });

    it('updates the history link when a card leaves the board', () => {
        placeCard(
            stream({
                id: 'c',
                removed: true,
                counts: { [BACKLOG]: 2, [NEXT]: 0 },
                history: { [NEXT]: 'No card is finished yet' },
            }),
        );

        expect(
            document.getElementById(`board-history-${NEXT}`).textContent,
        ).toBe('No card is finished yet');
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

const EPIC = 'epic';

function cell(lane, column, cards) {
    return `<section class="lp-board-lane__column"><span data-cell-count>${cards.length}</span>
        <div class="lp-board-lane__cell" id="board-cell-${lane}-${column}">${cards.map((id) => card(id, column)).join('')}</div>
    </section>`;
}

function laneHead(title, progress, expanded = 'true') {
    return `<header class="lp-board-lane__head"><button aria-expanded="${expanded}"></button><a class="lp-board-lane__title">${title}</a><span data-lane-progress>${progress}</span></header>`;
}

function renderLaneBoard() {
    document.body.innerHTML = `<div id="board">
        <div class="lp-board-lanes">
            <section class="lp-board-lane" id="board-lane-${EPIC}">
                ${laneHead('Epic', '0/2 done', 'false')}
                ${cell(EPIC, BACKLOG, ['a'])}${cell(EPIC, NEXT, ['b'])}
            </section>
            <section class="lp-board-lane">
                ${cell('other', BACKLOG, ['c'])}${cell('other', NEXT, [])}
            </section>
        </div>
        <div class="lp-board-list">
            <div class="lp-board-list__header"></div>
            ${row('a', BACKLOG)}${row('c', BACKLOG)}${row(EPIC, NEXT)}${row('b', NEXT)}
        </div>
    </div>`;
}

function laneStream({
    id,
    column,
    lane,
    after = '',
    rowAfter = '',
    head = false,
    body,
}) {
    const holder = document.createElement('div');
    const laneAttribute = lane === undefined ? '' : `data-lane="${lane}"`;
    const content = body ?? card(id, column, id, 'bbb') + row(id, column);
    holder.innerHTML = `<turbo-stream action="board-place" target="board-card-${id}" data-counts='{}' data-history='{}' data-column-id="${column}" data-after="${after}" data-row-after="${rowAfter}" ${laneAttribute} ${head ? 'data-lane-head' : ''}><template>${content}</template></turbo-stream>`;

    return holder.firstElementChild;
}

const cellCount = (lane, column) =>
    document
        .getElementById(`board-cell-${lane}-${column}`)
        .closest('.lp-board-lane__column')
        .querySelector('[data-cell-count]').textContent;

describe('board-place on a board with lanes', () => {
    beforeEach(renderLaneBoard);

    it('moves a card into the cell of its lane and recounts both cells', () => {
        const placed = vi.fn();
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            laneStream({
                id: 'a',
                column: NEXT,
                lane: EPIC,
                after: 'b',
                rowAfter: 'b',
            }),
        );

        expect(order(`#board-cell-${EPIC}-${NEXT} .lp-board-card`)).toEqual([
            'b',
            'a',
        ]);
        expect(order(`#board-cell-${EPIC}-${BACKLOG} .lp-board-card`)).toEqual(
            [],
        );
        expect(cellCount(EPIC, NEXT)).toBe('2');
        expect(cellCount(EPIC, BACKLOG)).toBe('0');
        expect(placed).toHaveBeenCalledOnce();
    });

    it('moves a card from one lane to another', () => {
        placeCard(
            laneStream({ id: 'a', column: NEXT, lane: 'other', rowAfter: 'b' }),
        );

        expect(order(`#board-cell-other-${NEXT} .lp-board-card`)).toEqual([
            'a',
        ]);
        expect(cellCount('other', NEXT)).toBe('1');
        expect(cellCount(EPIC, BACKLOG)).toBe('0');
    });

    it('recounts the cell a removed card leaves', () => {
        const holder = document.createElement('div');
        holder.innerHTML = `<turbo-stream action="board-place" target="board-card-a" data-counts='{}' data-history='{}' data-removed="1"><template></template></turbo-stream>`;

        placeCard(holder.firstElementChild);

        expect(document.getElementById('board-card-a')).toBeNull();
        expect(cellCount(EPIC, BACKLOG)).toBe('0');
    });

    it('reports a miss when the card it follows sits in another cell', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(
            laneStream({
                id: 'd',
                column: NEXT,
                lane: 'other',
                after: 'b',
                rowAfter: 'b',
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });

    it('reports a miss when the page lacks the cell', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(laneStream({ id: 'a', column: NEXT, lane: 'unknown-epic' }));

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });

    it('reports a miss when the stream names no lane', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(laneStream({ id: 'a', column: NEXT }));

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });

    it('reports a miss when a lane epic comes as a card face', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(laneStream({ id: EPIC, column: NEXT, lane: 'other' }));

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });

    it('morphs the head of a lane epic, keeps its collapse, and places its row', () => {
        const section = document.getElementById(`board-lane-${EPIC}`);
        const head = section.querySelector('.lp-board-lane__head');
        const placed = vi.fn();
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            laneStream({
                id: EPIC,
                column: BACKLOG,
                lane: 'other',
                rowAfter: 'c',
                head: true,
                body: laneHead('Renamed', '1/2 done') + row(EPIC, BACKLOG),
            }),
        );

        expect(section.querySelector('.lp-board-lane__head')).toBe(head);
        expect(head.querySelector('.lp-board-lane__title').textContent).toBe(
            'Renamed',
        );
        expect(head.querySelector('[data-lane-progress]').textContent).toBe(
            '1/2 done',
        );
        expect(head.querySelector('button').getAttribute('aria-expanded')).toBe(
            'false',
        );
        expect(order('.lp-board-list__row')).toEqual(['a', 'c', EPIC, 'b']);
        expect(
            document.getElementById(`board-row-${EPIC}`).dataset.columnId,
        ).toBe(BACKLOG);
        expect(placed).toHaveBeenCalledOnce();
        expect(placed.mock.calls[0][0].target).toBe(section);
        expect(placed.mock.calls[0][0].detail).toEqual({ cardId: EPIC });
    });

    it('reports a miss for a lane head the page has no lane for', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(
            laneStream({
                id: 'new-epic',
                column: NEXT,
                lane: 'other',
                head: true,
                body: laneHead('New', '0/0 done') + row('new-epic', NEXT),
            }),
        );

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });
});

describe('board-place on a board with no lane', () => {
    it('reports a miss when the stream names a lane', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;

        placeCard(laneStream({ id: 'a', column: NEXT, lane: 'other' }));

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
    });
});
