import { StreamActions } from '@hotwired/turbo';
import { recountCells } from './board_place.js';

const GROUP = ':scope > [data-board-drag-target="group"]';

/**
 * The board-structure stream action brings the columns, the lanes and the
 * rules banner to the server's skeleton. It keys columns by id and lanes by
 * key, changes only what differs, and never recreates a card.
 */
export function applyStructure(stream) {
    const board = document.getElementById('board');
    if (!board) {
        return;
    }

    const fresh = stream.querySelector('template').content.cloneNode(true);
    const tags = new Map(
        [
            ...fresh.querySelectorAll(
                '[data-board-structure-tags] > [data-column-tag]',
            ),
        ].map((span) => [span.dataset.columnTag, span.innerHTML]),
    );
    syncBanner(board, fresh.querySelector('.lp-board-rules'));
    syncLayout(
        board.querySelector(':scope > [data-board-view-target="board"]'),
        fresh.querySelector('[data-board-view-target="board"]'),
    );
    // A lane epic is its lane head, so a new lane takes its card face away. Its list row stays.
    board
        .querySelectorAll('.lp-board-lane--epic')
        .forEach((lane) =>
            document
                .getElementById(`board-card-${lane.dataset.lane}`)
                ?.remove(),
        );
    syncRows(board.querySelector('#board-list > .lp-board-list'), tags);
    recountCells();

    board.dataset.boardStructureDigest = stream.dataset.structureDigest;
    document.dispatchEvent(
        new CustomEvent('board:structure-changed', { bubbles: true }),
    );
}

function syncBanner(board, fresh) {
    const current = board.querySelector(':scope > .lp-board-rules');
    if (!fresh) {
        current?.remove();

        return;
    }
    if (current?.outerHTML === fresh.outerHTML) {
        return;
    }
    if (current) {
        current.replaceWith(fresh);
    } else {
        board.querySelector(':scope > .lp-board-filter-empty')?.before(fresh);
    }
}

function syncLayout(current, fresh) {
    if (!current || !fresh) {
        return;
    }
    const hasLanes = (layout) =>
        layout.classList.contains('lp-board__columns--lanes');
    if (hasLanes(current) !== hasLanes(fresh)) {
        switchLayout(current, fresh);
    } else if (hasLanes(fresh)) {
        syncLanes(
            current.querySelector('.lp-board-lanes'),
            fresh.querySelector('.lp-board-lanes'),
        );
    } else {
        syncColumns(current, fresh);
    }
}

function switchLayout(current, fresh) {
    // The list view hides the board layout, and the new one must stay hidden too.
    fresh.hidden = current.hidden;
    current.before(fresh);
    current
        .querySelectorAll('[data-board-drag-target="group"]')
        .forEach((group) =>
            moveCards(group, home(fresh, group.dataset.column)),
        );
    current.remove();
}

/** A new lane comes empty. A removed lane gives its cards to the Other cards lane. */
function syncLanes(parent, freshParent) {
    const lanes = [...freshParent.children].map((freshLane) => {
        const lane = child(parent, 'lane', freshLane.dataset.lane);
        if (!lane) {
            return freshLane;
        }
        syncColumns(
            lane.querySelector(
                ':scope > .lp-board-lane__body > .lp-board-lane__cells',
            ),
            freshLane.querySelector(
                ':scope > .lp-board-lane__body > .lp-board-lane__cells',
            ),
        );

        return lane;
    });
    const other = lanes.find((lane) => lane.dataset.lane === 'other');
    for (const lane of stale(parent, lanes)) {
        lane.querySelectorAll('[data-board-drag-target="group"]').forEach(
            (cell) =>
                moveCards(cell, other && home(other, cell.dataset.column)),
        );
        lane.remove();
    }
    arrange(parent, lanes);
}

function syncColumns(parent, freshParent) {
    const sections = [...freshParent.children].map((freshSection) => {
        const section = child(
            parent,
            'columnId',
            freshSection.dataset.columnId,
        );
        if (!section) {
            return freshSection;
        }
        syncColumn(section, freshSection);

        return section;
    });
    stale(parent, sections).forEach((section) => section.remove());
    arrange(parent, sections);
}

function syncColumn(section, fresh) {
    syncAttributes(section, fresh);
    const head = section.querySelector(':scope > header');
    const freshHead = fresh.querySelector(':scope > header');
    if (head && freshHead && head.outerHTML !== freshHead.outerHTML) {
        head.replaceWith(freshHead);
    }

    const group = section.querySelector(GROUP);
    const freshGroup = fresh.querySelector(GROUP);
    syncAttributes(group, freshGroup);
    const foot = siblingsAfter(group);
    const freshFoot = siblingsAfter(freshGroup);
    if (outerHtml(foot) !== outerHtml(freshFoot)) {
        foot.forEach((node) => node.remove());
        group.after(...freshFoot);
    }
}

function syncRows(list, tags) {
    if (!list) {
        return;
    }
    const byColumn = new Map([...tags.keys()].map((id) => [id, []]));
    list.querySelectorAll(':scope > .lp-board-list__row').forEach((row) => {
        const rows = byColumn.get(row.dataset.columnId);
        if (!rows) {
            row.remove();

            return;
        }
        const tag = row.querySelector('[data-column-tag]');
        const html = tags.get(row.dataset.columnId);
        if (tag && tag.innerHTML !== html) {
            tag.innerHTML = html;
        }
        rows.push(row);
    });
    arrange(
        list,
        [...byColumn.values()].flat(),
        list.querySelector(':scope > .lp-board-list__header'),
    );
}

/** The cell of a column in the Other cards lane, or the column group with no lanes. */
function home(layout, column) {
    return [
        ...layout.querySelectorAll('[data-board-drag-target="group"]'),
    ].find(
        (group) =>
            group.dataset.column === column &&
            (group.dataset.lane ?? 'other') === 'other',
    );
}

/** A face from an epic lane hides its parent badge, so an empty digest makes the card pass re-place it. */
function moveCards(from, to) {
    if (!to) {
        return;
    }
    const cards = [...from.querySelectorAll(':scope > .lp-board-card')];
    if ((from.dataset.lane ?? 'other') !== 'other') {
        cards.forEach((card) => (card.dataset.cardDigest = ''));
    }
    to.append(...cards);
}

/** Puts the nodes in order after the anchor, and moves only a node out of place, because a move reconnects its controllers. */
function arrange(parent, nodes, anchor = null) {
    let previous = anchor;
    for (const node of nodes) {
        const expected = previous
            ? previous.nextElementSibling
            : parent.firstElementChild;
        if (node !== expected) {
            if (previous) {
                previous.after(node);
            } else {
                parent.prepend(node);
            }
        }
        previous = node;
    }
}

function child(parent, key, value) {
    return [...parent.children].find((node) => node.dataset[key] === value);
}

function stale(parent, kept) {
    return [...parent.children].filter((node) => !kept.includes(node));
}

function syncAttributes(target, source) {
    [...target.attributes]
        .filter(({ name }) => !source.hasAttribute(name))
        .forEach(({ name }) => target.removeAttribute(name));
    for (const { name, value } of source.attributes) {
        if (target.getAttribute(name) !== value) {
            target.setAttribute(name, value);
        }
    }
}

function siblingsAfter(node) {
    const siblings = [];
    for (
        let next = node.nextElementSibling;
        next;
        next = next.nextElementSibling
    ) {
        siblings.push(next);
    }

    return siblings;
}

function outerHtml(nodes) {
    return nodes.map((node) => node.outerHTML).join('');
}

StreamActions['board-structure'] = function boardStructure() {
    applyStructure(this);
};
