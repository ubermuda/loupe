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
    deckEpic = null,
}) {
    const holder = document.createElement('div');
    const placement = removed
        ? `data-removed="1"${deckEpic ? ` data-deck-epic="${deckEpic}"` : ''}`
        : `data-column-id="${column}" data-after="${after}" data-row-after="${rowAfter}"`;
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
    it('takes a card placed on the board out of the Up next deck it left', () => {
        document
            .getElementById('board')
            .insertAdjacentHTML(
                'afterbegin',
                '<div class="lp-deck"><article id="board-deck-card-d" data-card-id="d"></article></div>',
            );

        placeCard(
            stream({
                id: 'd',
                column: NEXT,
                after: 'c',
                rowAfter: 'c',
                counts: { [BACKLOG]: 2, [NEXT]: 2 },
            }),
        );

        expect(order(`#board-group-${NEXT} .lp-board-card`)).toEqual([
            'c',
            'd',
        ]);
        expect(document.getElementById('board-deck-card-d')).toBeNull();
    });

    it('names the epic of the deck a placed card left', () => {
        document
            .getElementById('board')
            .insertAdjacentHTML(
                'afterbegin',
                '<div class="lp-deck" data-lane="epic"><article id="board-deck-card-d" data-card-id="d"></article></div>',
            );
        const details = [];
        document.addEventListener('board:placed', (event) =>
            details.push(event.detail),
        );

        placeCard(
            stream({
                id: 'd',
                column: NEXT,
                after: 'c',
                rowAfter: 'c',
                counts: { [BACKLOG]: 2, [NEXT]: 2 },
            }),
        );

        expect(details).toEqual([{ cardId: 'd', leftDeck: 'epic' }]);
    });

    it('keeps a deck card when its card is placed off the board', () => {
        document
            .getElementById('board')
            .insertAdjacentHTML(
                'afterbegin',
                '<div class="lp-deck"><article id="board-deck-card-d" data-card-id="d"></article></div>',
            );

        placeCard(
            stream({
                id: 'd',
                removed: true,
                counts: { [BACKLOG]: 2, [NEXT]: 1 },
            }),
        );

        expect(document.getElementById('board-deck-card-d')).not.toBeNull();
    });

    it('keeps a deck card when its placement misses', () => {
        document
            .getElementById('board')
            .insertAdjacentHTML(
                'afterbegin',
                '<div class="lp-deck"><article id="board-deck-card-d" data-card-id="d"></article></div>',
            );
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });

        placeCard(
            stream({
                id: 'd',
                column: NEXT,
                after: 'not-on-the-page',
                counts: { [BACKLOG]: 2, [NEXT]: 2 },
            }),
        );

        expect(missed).toHaveBeenCalledOnce();
        expect(document.getElementById('board-deck-card-d')).not.toBeNull();
    });

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

    it('names the epic whose deck a removed Backlog card joins', () => {
        const placed = vi.fn();
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            stream({
                id: 'a',
                removed: true,
                deckEpic: 'epic',
                counts: { [BACKLOG]: 1, [NEXT]: 1 },
            }),
        );

        expect(placed.mock.calls[0][0].detail).toEqual({
            cardId: 'a',
            removed: true,
            deckEpic: 'epic',
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

    it('places the card face and skips the row while the list is not loaded', () => {
        document.querySelector('.lp-board-list').remove();
        const missed = vi.fn();
        const placed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            stream({
                id: 'a',
                column: NEXT,
                after: 'c',
                rowAfter: 'c',
                counts: { [BACKLOG]: 1, [NEXT]: 2 },
            }),
        );

        expect(order(`#board-group-${NEXT} .lp-board-card`)).toEqual([
            'c',
            'a',
        ]);
        expect(document.querySelector('.lp-board-list__row')).toBeNull();
        expect(document.getElementById(`board-count-${NEXT}`).textContent).toBe(
            '2',
        );
        expect(missed).not.toHaveBeenCalled();
        expect(placed).toHaveBeenCalledOnce();
    });
});

const EPIC = 'epic';

function cell(lane, column, cards) {
    return `<section class="lp-board-lane__column"><span data-cell-count>${cards.length}</span>
        <div class="lp-board-lane__cell" id="board-cell-${lane}-${column}">${cards.map((id) => card(id, column)).join('')}</div>
    </section>`;
}

function laneHead(title, progress, expanded = 'true') {
    return `<header class="lp-board-lane__head"><button class="lp-board-lane__collapse" aria-expanded="${expanded}"></button><span class="lp-board-card__number">#7</span><a class="lp-board-lane__title">${title}</a><span data-lane-progress>${progress}</span></header>`;
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
    laneAfter = '',
    body,
}) {
    const holder = document.createElement('div');
    const laneAttribute = lane === undefined ? '' : `data-lane="${lane}"`;
    const headAttributes = head
        ? `data-lane-head data-lane-after="${laneAfter}"`
        : '';
    const content = body ?? card(id, column, id, 'bbb') + row(id, column);
    holder.innerHTML = `<turbo-stream action="board-place" target="board-card-${id}" data-counts='{}' data-history='{}' data-column-id="${column}" data-after="${after}" data-row-after="${rowAfter}" ${laneAttribute} ${headAttributes}><template>${content}</template></turbo-stream>`;

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

    it('recounts the cell a dropped card left before the stream came', () => {
        document
            .getElementById(`board-cell-other-${NEXT}`)
            .append(document.getElementById('board-card-a'));

        placeCard(
            laneStream({ id: 'a', column: NEXT, lane: 'other', rowAfter: 'b' }),
        );

        expect(cellCount(EPIC, BACKLOG)).toBe('0');
        expect(cellCount('other', NEXT)).toBe('1');
    });

    it('leaves the ghost of a drag out of the cell count', () => {
        const ghost = document.createElement('div');
        ghost.className = 'lp-board-card lp-board__ghost';
        document.getElementById(`board-cell-${EPIC}-${BACKLOG}`).append(ghost);

        placeCard(
            laneStream({ id: 'c', column: NEXT, lane: 'other', rowAfter: 'b' }),
        );

        expect(cellCount(EPIC, BACKLOG)).toBe('1');
    });

    it('reports a miss when a removed card is a lane the page draws', () => {
        const missed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        const before = document.body.innerHTML;
        const holder = document.createElement('div');
        holder.innerHTML = `<turbo-stream action="board-place" target="board-card-${EPIC}" data-counts='{}' data-history='{}' data-removed="1"><template></template></turbo-stream>`;

        placeCard(holder.firstElementChild);

        expect(document.body.innerHTML).toBe(before);
        expect(missed).toHaveBeenCalledOnce();
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
        expect(section.getAttribute('aria-label')).toBe('#7 Renamed');
        expect(placed).toHaveBeenCalledOnce();
        expect(placed.mock.calls[0][0].target).toBe(section);
        expect(placed.mock.calls[0][0].detail).toEqual({ cardId: EPIC });
    });

    it('morphs a lane head that comes with no row, and removes the row the list had', () => {
        const placed = vi.fn();
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            laneStream({
                id: EPIC,
                column: 'col-waiting',
                head: true,
                body: laneHead('Waiting', '1/2 done'),
            }),
        );

        expect(
            document.querySelector(`#board-lane-${EPIC} .lp-board-lane__title`)
                .textContent,
        ).toBe('Waiting');
        expect(order('.lp-board-list__row')).toEqual(['a', 'c', 'b']);
        expect(placed).toHaveBeenCalledOnce();

        placeCard(
            laneStream({
                id: EPIC,
                column: 'col-waiting',
                head: true,
                body: laneHead('Still waiting', '1/2 done'),
            }),
        );

        expect(order('.lp-board-list__row')).toEqual(['a', 'c', 'b']);
        expect(
            document.querySelector('.lp-board-list').textContent,
        ).not.toContain('null');
    });

    it('morphs the head of a lane epic and skips its row while the list is not loaded', () => {
        document.querySelector('.lp-board-list').remove();
        const missed = vi.fn();
        const placed = vi.fn();
        document.addEventListener('board:place-missed', missed, { once: true });
        document.addEventListener('board:placed', placed, { once: true });

        placeCard(
            laneStream({
                id: EPIC,
                column: NEXT,
                lane: 'other',
                rowAfter: 'c',
                head: true,
                body: laneHead('Renamed', '1/2 done') + row(EPIC, NEXT),
            }),
        );

        expect(
            document.querySelector(`#board-lane-${EPIC} .lp-board-lane__title`)
                .textContent,
        ).toBe('Renamed');
        expect(document.querySelector('.lp-board-list__row')).toBeNull();
        expect(missed).not.toHaveBeenCalled();
        expect(placed).toHaveBeenCalledOnce();
    });

    it('takes a lane epic placed as a lane head out of the deck it left', () => {
        document
            .querySelector('.lp-board-lane')
            .insertAdjacentHTML(
                'beforeend',
                `<article id="board-deck-card-${EPIC}" data-card-id="${EPIC}"></article>`,
            );

        placeCard(
            laneStream({
                id: EPIC,
                column: NEXT,
                head: true,
                body: laneHead('Epic', '0/2 done') + row(EPIC, NEXT),
            }),
        );

        expect(document.getElementById(`board-deck-card-${EPIC}`)).toBeNull();
    });

    it('keeps the deck copy of a lane epic that still waits in the Backlog', () => {
        document
            .querySelector('.lp-board-lane')
            .insertAdjacentHTML(
                'beforeend',
                `<div class="lp-deck" data-column="col-waiting"><article id="board-deck-card-${EPIC}" data-card-id="${EPIC}"></article></div>`,
            );

        placeCard(
            laneStream({
                id: EPIC,
                column: 'col-waiting',
                head: true,
                body: laneHead('Renamed', '0/2 done'),
            }),
        );

        expect(
            document.getElementById(`board-deck-card-${EPIC}`),
        ).not.toBeNull();
    });

    it('drops the stale mark of a lane head it morphs', () => {
        const head = document.querySelector(
            `#board-lane-${EPIC} .lp-board-lane__head`,
        );
        head.classList.add('lp-board-lane__head--stale');
        head.setAttribute('data-board-stale', '');
        head.title = 'This card may be out of date';
        head.insertAdjacentHTML(
            'beforeend',
            '<span class="sr-only" data-board-stale-text>This card may be out of date</span>',
        );

        placeCard(
            laneStream({
                id: EPIC,
                column: NEXT,
                lane: 'other',
                rowAfter: 'c',
                head: true,
                body: laneHead('Epic', '0/2 done') + row(EPIC, NEXT),
            }),
        );

        expect(head.className).toBe('lp-board-lane__head');
        expect(head.hasAttribute('data-board-stale')).toBe(false);
        expect(head.hasAttribute('title')).toBe(false);
        expect(head.querySelector('[data-board-stale-text]')).toBeNull();
    });

    describe('the order of the lanes', () => {
        const SECOND = 'epic-2';
        const lanes = () =>
            [...document.querySelectorAll('.lp-board-lanes > section')].map(
                (section) => section.id || 'other',
            );
        const headStream = (id, laneAfter) =>
            laneStream({
                id,
                column: NEXT,
                lane: 'other',
                rowAfter: 'b',
                head: true,
                laneAfter,
                body: laneHead(id, '0/0 done') + row(id, NEXT),
            });

        beforeEach(() => {
            document
                .getElementById(`board-lane-${EPIC}`)
                .insertAdjacentHTML(
                    'afterend',
                    `<section class="lp-board-lane" id="board-lane-${SECOND}">${laneHead('Second', '0/0 done')}</section>`,
                );
        });

        it('moves a lane after the lane the server names', () => {
            placeCard(headStream(EPIC, SECOND));

            expect(lanes()).toEqual([
                `board-lane-${SECOND}`,
                `board-lane-${EPIC}`,
                'other',
            ]);
        });

        it('moves a lane first when no lane comes before it', () => {
            placeCard(headStream(SECOND, ''));

            expect(lanes()).toEqual([
                `board-lane-${SECOND}`,
                `board-lane-${EPIC}`,
                'other',
            ]);
        });

        it('keeps a lane that is already in its place', () => {
            const section = document.getElementById(`board-lane-${SECOND}`);

            placeCard(headStream(SECOND, EPIC));

            expect(lanes()).toEqual([
                `board-lane-${EPIC}`,
                `board-lane-${SECOND}`,
                'other',
            ]);
            expect(document.getElementById(`board-lane-${SECOND}`)).toBe(
                section,
            );
        });

        it('reports a miss when the page lacks the lane before it', () => {
            const missed = vi.fn();
            document.addEventListener('board:place-missed', missed, {
                once: true,
            });
            const before = document.body.innerHTML;

            placeCard(headStream(EPIC, 'unknown-epic'));

            expect(document.body.innerHTML).toBe(before);
            expect(missed).toHaveBeenCalledOnce();
        });
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
