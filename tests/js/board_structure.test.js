/** @vitest-environment jsdom */
import { StreamActions } from '@hotwired/turbo';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { applyStructure } from '../../assets/lib/board_structure.js';

const BACKLOG = { id: 'col-backlog', label: 'Backlog', tone: 'slate' };
const NEXT = { id: 'col-next', label: 'Next', tone: 'blue' };
const DONE = { id: 'col-done', label: 'Done', tone: 'green', terminal: true };
const EPIC = 'epic-1';

function head(column, cell = false) {
    const count = cell
        ? '<span class="lp-board__column-count" data-cell-count>0</span>'
        : `<span class="lp-board__column-count" id="board-count-${column.id}">0</span>`;

    return `<header class="lp-board__column-head"><span class="lp-tone-dot lp-tone-dot--${column.tone}"></span><h2 class="lp-board__column-title">${column.label}</h2>${count}</header>`;
}

function foot(column) {
    const history = column.terminal
        ? `<p class="lp-board__column-note">Last 14 days</p><a class="lp-board__column-link" id="board-history-${column.id}" data-history-total="0">History</a>`
        : '';

    return `${history}<a class="lp-board__add-card">Add card</a>`;
}

function slug(column) {
    return column.slug ?? column.label.toLowerCase();
}

function card(id) {
    return `<article class="lp-board-card" id="board-card-${id}" data-card-id="${id}" data-card-digest="d">${id}</article>`;
}

function cardsOf(cards, laneKey, column) {
    return (cards[`${laneKey}:${column.id}`] ?? []).map(card).join('');
}

function plainColumns(columns, cards) {
    return `<div class="lp-board__columns" data-board-view-target="board">${columns
        .map(
            (column) =>
                `<section class="lp-board__column" id="board-column-${column.id}" aria-label="${column.label}" data-column-slug="${slug(column)}" data-column-id="${column.id}">${head(column)}<div class="lp-board__group" id="board-group-${column.id}" data-board-drag-target="group" data-column="${column.id}" data-rankable="${column.terminal ? '0' : '1'}">${cardsOf(cards, 'plain', column)}</div>${foot(column)}</section>`,
        )
        .join('')}</div>`;
}

function lane(laneKey, columns, cards) {
    const epic = laneKey !== 'other';
    const attributes = epic
        ? `id="board-lane-${laneKey}" data-lane="${laneKey}" data-controller="board-lane"`
        : 'data-lane="other"';
    const cells = columns
        .map(
            (column) =>
                `<section class="lp-board__column lp-board-lane__column" aria-label="${column.label}" data-column-slug="${slug(column)}" data-column-id="${column.id}">${head(column, true)}<div class="lp-board-lane__cell" id="board-cell-${laneKey}-${column.id}" data-board-drag-target="group" data-column="${column.id}" data-lane="${laneKey}" data-rankable="${column.terminal ? '0' : '1'}">${cardsOf(cards, laneKey, column)}</div>${epic ? '' : foot(column)}</section>`,
        )
        .join('');

    return `<section class="lp-board-lane lp-board-lane--${epic ? 'epic' : 'other'}" ${attributes}><header class="lp-board-lane__head"><h2>${laneKey}</h2></header><div class="lp-board-lane__body"><div class="lp-board-lane__cells">${cells}</div></div></section>`;
}

function laneColumns(columns, lanes, cards) {
    return `<div class="lp-board__columns lp-board__columns--lanes" data-board-view-target="board"><div class="lp-board-lanes">${[
        ...lanes,
        'other',
    ]
        .map((key) => lane(key, columns, cards))
        .join('')}</div></div>`;
}

function layout(columns, lanes, cards = {}) {
    return lanes === null
        ? plainColumns(columns, cards)
        : laneColumns(columns, lanes, cards);
}

function banner(text) {
    return text
        ? `<section class="lp-board-rules" data-testid="bridge-rules-banner">${text}</section>`
        : '';
}

function tag(column) {
    return `<span class="lp-column-tag lp-column-tag--${column.tone}">${column.label}</span>`;
}

function row(id, column) {
    return `<a class="lp-board-list__row" id="board-row-${id}" data-card-id="${id}" data-column-id="${column.id}"><span>#${id}</span><span data-column-tag="${column.id}">${tag(column)}</span></a>`;
}

function renderPage({ columns, lanes = null, cards, rows, rules = '' }) {
    document.body.innerHTML = `<div id="board" data-board-structure-digest="old"><div class="lp-board-toolbar"></div>${banner(rules)}<p class="lp-board-filter-empty"></p><p class="lp-board__message"></p>${layout(columns, lanes, cards)}<turbo-frame id="board-list" data-board-view-target="list"><div class="lp-board-list"><div class="lp-board-list__header"></div>${rows.map(([id, column]) => row(id, column)).join('')}</div></turbo-frame></div>`;
}

function stream({ columns, lanes = null, rules = '', digest = 'new' }) {
    const holder = document.createElement('div');
    const tags = columns
        .map(
            (column) =>
                `<span data-column-tag="${column.id}">${tag(column)}</span>`,
        )
        .join('');
    holder.innerHTML = `<turbo-stream action="board-structure" target="board" data-structure-digest="${digest}"><template>${banner(rules)}${layout(columns, lanes)}<div data-board-structure-tags hidden>${tags}</div></template></turbo-stream>`;

    return holder.firstElementChild;
}

const byId = (id) => document.getElementById(id);

const cardIds = (selector) =>
    [...document.querySelectorAll(`${selector} > .lp-board-card`)].map(
        (node) => node.dataset.cardId,
    );

const columnOrder = (parent) =>
    [...document.querySelector(parent).children].map(
        (section) => section.dataset.columnId,
    );

const rowOrder = () =>
    [...document.querySelectorAll('.lp-board-list__row')].map(
        (node) => node.dataset.cardId,
    );

function snapshot(ids) {
    return Object.fromEntries(ids.map((id) => [id, byId(`board-card-${id}`)]));
}

function expectSame(before) {
    for (const [id, node] of Object.entries(before)) {
        expect(byId(`board-card-${id}`)).toBe(node);
    }
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('board-structure with no lanes', () => {
    const page = {
        columns: [BACKLOG, NEXT, DONE],
        cards: { 'plain:col-backlog': ['a', 'b'], 'plain:col-next': ['c'] },
        rows: [
            ['a', BACKLOG],
            ['b', BACKLOG],
            ['c', NEXT],
        ],
    };

    it('registers the stream action', () => {
        expect(typeof StreamActions['board-structure']).toBe('function');
    });

    it('leaves a board with an unchanged structure untouched', () => {
        renderPage(page);
        const section = byId(`board-column-${NEXT.id}`);
        const columnHead = section.querySelector('header');
        const addCard = section.querySelector('.lp-board__add-card');
        const cards = snapshot(['a', 'b', 'c']);

        applyStructure(stream(page));

        expect(byId(`board-column-${NEXT.id}`)).toBe(section);
        expect(section.querySelector('header')).toBe(columnHead);
        expect(section.querySelector('.lp-board__add-card')).toBe(addCard);
        expectSame(cards);
    });

    it('renames a column in its head, label, slug and list tags', () => {
        renderPage(page);
        const group = byId(`board-group-${NEXT.id}`);
        const otherHead = byId(`board-column-${BACKLOG.id}`).querySelector(
            'header',
        );
        const cards = snapshot(['a', 'b', 'c']);
        const renamed = { ...NEXT, label: 'Ready', slug: 'ready' };

        applyStructure(stream({ columns: [BACKLOG, renamed, DONE] }));

        const section = byId(`board-column-${NEXT.id}`);
        expect(section.getAttribute('aria-label')).toBe('Ready');
        expect(section.dataset.columnSlug).toBe('ready');
        expect(section.querySelector('h2').textContent).toBe('Ready');
        expect(byId(`board-group-${NEXT.id}`)).toBe(group);
        expect(byId(`board-column-${BACKLOG.id}`).querySelector('header')).toBe(
            otherHead,
        );
        expect(
            byId('board-row-c').querySelector('[data-column-tag]').textContent,
        ).toBe('Ready');
        expect(
            byId('board-row-a').querySelector('[data-column-tag]').textContent,
        ).toBe('Backlog');
        expectSame(cards);
    });

    it('changes the tone of a column', () => {
        renderPage(page);
        const cards = snapshot(['a', 'b', 'c']);

        applyStructure(
            stream({ columns: [{ ...BACKLOG, tone: 'red' }, NEXT, DONE] }),
        );

        expect(
            byId(`board-column-${BACKLOG.id}`).querySelector(
                '.lp-tone-dot--red',
            ),
        ).not.toBeNull();
        expect(
            byId('board-row-a').querySelector('.lp-column-tag--red'),
        ).not.toBeNull();
        expectSame(cards);
    });

    it('adds a column in its place', () => {
        renderPage(page);
        const cards = snapshot(['a', 'b', 'c']);
        const review = { id: 'col-review', label: 'Review', tone: 'amber' };

        applyStructure(stream({ columns: [BACKLOG, NEXT, review, DONE] }));

        expect(columnOrder('.lp-board__columns')).toEqual([
            BACKLOG.id,
            NEXT.id,
            review.id,
            DONE.id,
        ]);
        expect(cardIds(`#board-group-${review.id}`)).toEqual([]);
        expectSame(cards);
    });

    it('removes a deleted column with its cards and rows', () => {
        renderPage(page);
        const cards = snapshot(['c']);

        applyStructure(stream({ columns: [NEXT, DONE] }));

        expect(byId(`board-column-${BACKLOG.id}`)).toBeNull();
        expect(byId('board-card-a')).toBeNull();
        expect(byId('board-card-b')).toBeNull();
        expect(rowOrder()).toEqual(['c']);
        expectSame(cards);
    });

    it('reorders the columns and the list rows', () => {
        renderPage(page);
        const sections = [BACKLOG, NEXT, DONE].map((column) =>
            byId(`board-column-${column.id}`),
        );
        const cards = snapshot(['a', 'b', 'c']);

        applyStructure(stream({ columns: [NEXT, DONE, BACKLOG] }));

        expect(columnOrder('.lp-board__columns')).toEqual([
            NEXT.id,
            DONE.id,
            BACKLOG.id,
        ]);
        expect(byId(`board-column-${BACKLOG.id}`)).toBe(sections[0]);
        expect(byId(`board-column-${NEXT.id}`)).toBe(sections[1]);
        expect(rowOrder()).toEqual(['c', 'a', 'b']);
        expectSame(cards);
    });

    it('follows a terminal flag in the rank flag and the foot', () => {
        renderPage(page);
        const cards = snapshot(['a', 'b', 'c']);

        applyStructure(
            stream({
                columns: [
                    BACKLOG,
                    { ...NEXT, terminal: true },
                    { ...DONE, terminal: false },
                ],
            }),
        );

        expect(byId(`board-group-${NEXT.id}`).dataset.rankable).toBe('0');
        expect(byId(`board-history-${NEXT.id}`)).not.toBeNull();
        expect(byId(`board-group-${DONE.id}`).dataset.rankable).toBe('1');
        expect(byId(`board-history-${DONE.id}`)).toBeNull();
        const nextSection = byId(`board-column-${NEXT.id}`);
        expect([...nextSection.children].map((node) => node.className)).toEqual(
            [
                'lp-board__column-head',
                'lp-board__group',
                'lp-board__column-note',
                'lp-board__column-link',
                'lp-board__add-card',
            ],
        );
        expectSame(cards);
    });

    it('removes the card face of the epic whose lane is the first', () => {
        renderPage({
            ...page,
            cards: { ...page.cards, 'plain:col-next': ['c', EPIC] },
        });
        const cards = snapshot(['a', 'b', 'c']);

        applyStructure(stream({ columns: page.columns, lanes: [EPIC] }));

        expect(byId(`board-card-${EPIC}`)).toBeNull();
        expect(cardIds(`#board-cell-other-${NEXT.id}`)).toEqual(['c']);
        expectSame(cards);
    });

    it('switches to lanes when the first lane appears', () => {
        renderPage(page);
        const cards = snapshot(['a', 'b', 'c']);

        applyStructure(
            stream({ columns: [BACKLOG, NEXT, DONE], lanes: [EPIC] }),
        );

        expect(document.querySelectorAll('.lp-board__columns')).toHaveLength(1);
        expect(byId(`board-lane-${EPIC}`)).not.toBeNull();
        expect(cardIds(`#board-cell-${EPIC}-${BACKLOG.id}`)).toEqual([]);
        expect(cardIds(`#board-cell-other-${BACKLOG.id}`)).toEqual(['a', 'b']);
        expect(cardIds(`#board-cell-other-${NEXT.id}`)).toEqual(['c']);
        expect(
            byId(`board-cell-other-${BACKLOG.id}`)
                .closest('.lp-board-lane__column')
                .querySelector('[data-cell-count]').textContent,
        ).toBe('2');
        expectSame(cards);
    });

    it('keeps the board hidden behind the list when the layout switches', () => {
        renderPage(page);
        document.querySelector('[data-board-view-target="board"]').hidden =
            true;
        const cards = snapshot(['a', 'b', 'c']);

        applyStructure(
            stream({ columns: [BACKLOG, NEXT, DONE], lanes: [EPIC] }),
        );

        expect(
            document.querySelector('[data-board-view-target="board"]').hidden,
        ).toBe(true);
        expectSame(cards);
    });

    it('adds and removes the rules banner after the toolbar', () => {
        renderPage(page);

        applyStructure(stream({ columns: page.columns, rules: 'Dead rule' }));

        const rules = document.querySelector('.lp-board-rules');
        expect(rules.textContent).toBe('Dead rule');
        expect(rules.previousElementSibling.className).toBe('lp-board-toolbar');

        applyStructure(stream({ columns: page.columns, rules: 'Dead rule' }));
        expect(document.querySelector('.lp-board-rules')).toBe(rules);

        applyStructure(stream({ columns: page.columns, rules: 'Other rule' }));
        expect(document.querySelector('.lp-board-rules').textContent).toBe(
            'Other rule',
        );

        applyStructure(stream({ columns: page.columns }));
        expect(document.querySelector('.lp-board-rules')).toBeNull();
    });

    it('writes the digest, then says the structure changed', () => {
        renderPage(page);
        const changed = vi.fn(() =>
            expect(byId('board').dataset.boardStructureDigest).toBe('fresh'),
        );
        document.addEventListener('board:structure-changed', changed, {
            once: true,
        });

        applyStructure(stream({ columns: page.columns, digest: 'fresh' }));

        expect(changed).toHaveBeenCalledOnce();
    });

    it('does nothing on a page with no board', () => {
        document.body.innerHTML = '<p>Elsewhere</p>';
        const changed = vi.fn();
        document.addEventListener('board:structure-changed', changed, {
            once: true,
        });

        applyStructure(stream({ columns: page.columns }));

        expect(document.body.innerHTML).toBe('<p>Elsewhere</p>');
        expect(changed).not.toHaveBeenCalled();
        document.removeEventListener('board:structure-changed', changed);
    });
});

describe('board-structure with lanes', () => {
    const page = {
        columns: [BACKLOG, NEXT, DONE],
        lanes: [EPIC],
        cards: {
            [`${EPIC}:col-backlog`]: ['a'],
            [`${EPIC}:col-next`]: ['b'],
            'other:col-backlog': ['c'],
            'other:col-done': ['d'],
        },
        rows: [
            ['a', BACKLOG],
            ['c', BACKLOG],
            ['b', NEXT],
            ['d', DONE],
        ],
    };

    it('renames a column in every lane', () => {
        renderPage(page);
        const cells = [EPIC, 'other'].map((key) =>
            byId(`board-cell-${key}-${NEXT.id}`),
        );
        const cards = snapshot(['a', 'b', 'c', 'd']);

        applyStructure(
            stream({
                columns: [BACKLOG, { ...NEXT, label: 'Ready' }, DONE],
                lanes: [EPIC],
            }),
        );

        for (const [index, key] of [EPIC, 'other'].entries()) {
            const cell = byId(`board-cell-${key}-${NEXT.id}`);
            expect(cell).toBe(cells[index]);
            const section = cell.closest('.lp-board-lane__column');
            expect(section.getAttribute('aria-label')).toBe('Ready');
            expect(section.dataset.columnSlug).toBe('ready');
            expect(section.querySelector('h2').textContent).toBe('Ready');
        }
        expectSame(cards);
    });

    it('adds a column to every lane', () => {
        renderPage(page);
        const lane = byId(`board-lane-${EPIC}`);
        const cards = snapshot(['a', 'b', 'c', 'd']);
        const review = { id: 'col-review', label: 'Review', tone: 'amber' };

        applyStructure(
            stream({ columns: [BACKLOG, review, NEXT, DONE], lanes: [EPIC] }),
        );

        expect(byId(`board-lane-${EPIC}`)).toBe(lane);
        for (const key of [EPIC, 'other']) {
            expect(
                columnOrder(`[data-lane="${key}"] .lp-board-lane__cells`),
            ).toEqual([BACKLOG.id, review.id, NEXT.id, DONE.id]);
            expect(cardIds(`#board-cell-${key}-${review.id}`)).toEqual([]);
        }
        expectSame(cards);
    });

    it('removes a deleted column from every lane with its cards', () => {
        renderPage(page);
        const cards = snapshot(['b', 'd']);

        applyStructure(stream({ columns: [NEXT, DONE], lanes: [EPIC] }));

        expect(
            document.querySelector(`[data-column-id="${BACKLOG.id}"]`),
        ).toBeNull();
        expect(byId('board-card-a')).toBeNull();
        expect(byId('board-card-c')).toBeNull();
        expect(rowOrder()).toEqual(['b', 'd']);
        expectSame(cards);
    });

    it('removes the card face of the epic whose lane appears', () => {
        renderPage({
            ...page,
            cards: { ...page.cards, 'other:col-next': ['epic-2'] },
            rows: [...page.rows, ['epic-2', NEXT]],
        });
        const cards = snapshot(['a', 'b', 'c', 'd']);

        applyStructure(
            stream({ columns: page.columns, lanes: [EPIC, 'epic-2'] }),
        );

        expect(byId('board-lane-epic-2')).not.toBeNull();
        expect(byId('board-card-epic-2')).toBeNull();
        expect(byId('board-row-epic-2')).not.toBeNull();
        expectSame(cards);
    });

    it('adds a lane with empty cells and leaves the cards where they are', () => {
        renderPage(page);
        const cards = snapshot(['a', 'b', 'c', 'd']);

        applyStructure(
            stream({ columns: page.columns, lanes: [EPIC, 'epic-2'] }),
        );

        expect(
            [...document.querySelector('.lp-board-lanes').children].map(
                (node) => node.dataset.lane,
            ),
        ).toEqual([EPIC, 'epic-2', 'other']);
        for (const column of page.columns) {
            expect(cardIds(`#board-cell-epic-2-${column.id}`)).toEqual([]);
        }
        expect(cardIds(`#board-cell-${EPIC}-${BACKLOG.id}`)).toEqual(['a']);
        expect(cardIds(`#board-cell-other-${BACKLOG.id}`)).toEqual(['c']);
        expectSame(cards);
    });

    it('moves the cards of a removed lane into the Other cards lane', () => {
        renderPage({ ...page, lanes: [EPIC, 'epic-2'] });
        const cards = snapshot(['a', 'b', 'c', 'd']);

        applyStructure(stream({ columns: page.columns, lanes: ['epic-2'] }));

        expect(byId(`board-lane-${EPIC}`)).toBeNull();
        expect(cardIds(`#board-cell-other-${BACKLOG.id}`)).toEqual(['c', 'a']);
        expect(cardIds(`#board-cell-other-${NEXT.id}`)).toEqual(['b']);
        expect(
            byId(`board-cell-other-${BACKLOG.id}`)
                .closest('.lp-board-lane__column')
                .querySelector('[data-cell-count]').textContent,
        ).toBe('2');
        expectSame(cards);
    });

    it('clears the digest of a card that leaves an epic lane, so the card pass re-places its face', () => {
        renderPage({ ...page, lanes: [EPIC, 'epic-2'] });

        applyStructure(stream({ columns: page.columns, lanes: ['epic-2'] }));

        expect(byId('board-card-a').dataset.cardDigest).toBe('');
        expect(byId('board-card-b').dataset.cardDigest).toBe('');
        expect(byId('board-card-c').dataset.cardDigest).toBe('d');
        expect(byId('board-card-d').dataset.cardDigest).toBe('d');
    });

    it('clears the digest of the epic lane cards when the last lane goes', () => {
        renderPage(page);

        applyStructure(stream({ columns: page.columns }));

        expect(byId('board-card-a').dataset.cardDigest).toBe('');
        expect(byId('board-card-b').dataset.cardDigest).toBe('');
        expect(byId('board-card-c').dataset.cardDigest).toBe('d');
        expect(byId('board-card-d').dataset.cardDigest).toBe('d');
    });

    it('switches to plain columns when the last lane goes', () => {
        renderPage(page);
        const cards = snapshot(['a', 'b', 'c', 'd']);

        applyStructure(stream({ columns: page.columns }));

        expect(document.querySelector('.lp-board-lanes')).toBeNull();
        expect(document.querySelectorAll('.lp-board__columns')).toHaveLength(1);
        expect(cardIds(`#board-group-${BACKLOG.id}`)).toEqual(['a', 'c']);
        expect(cardIds(`#board-group-${NEXT.id}`)).toEqual(['b']);
        expect(cardIds(`#board-group-${DONE.id}`)).toEqual(['d']);
        expectSame(cards);
    });

    it('reorders the list rows by column and keeps their order within one', () => {
        renderPage(page);
        const cards = snapshot(['a', 'b', 'c', 'd']);

        applyStructure(
            stream({ columns: [DONE, NEXT, BACKLOG], lanes: [EPIC] }),
        );

        expect(rowOrder()).toEqual(['d', 'b', 'a', 'c']);
        expect(
            columnOrder(`[data-lane="other"] .lp-board-lane__cells`),
        ).toEqual([DONE.id, NEXT.id, BACKLOG.id]);
        expectSame(cards);
    });
});
