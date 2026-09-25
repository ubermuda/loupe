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
    holder.innerHTML = `<turbo-stream action="board-place" target="board-card-${id}" data-counts='${JSON.stringify(counts)}' ${placement}><template>${body}</template></turbo-stream>`;

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
