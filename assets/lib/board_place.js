import { StreamActions, morphElements } from '@hotwired/turbo';

/**
 * The board-place stream action puts one card, and its list row, where the
 * server says it sits. A removed card comes as data-removed with an empty
 * template, so every change carries the column counts through one path.
 * A page that lacks the container or an anchor, or shows the anchor card in
 * another container, changes nothing and reports board:place-missed, because
 * a guessed position would show a wrong order.
 */
export function placeCard(stream) {
    const cardId = stream.getAttribute('target').replace(/^board-card-/, '');
    const counts = JSON.parse(stream.dataset.counts || '{}');
    const history = JSON.parse(stream.dataset.history || '{}');
    const sourceCell = document.getElementById(
        `board-card-${cardId}`,
    )?.parentElement;

    if (stream.hasAttribute('data-removed')) {
        document.getElementById(`board-card-${cardId}`)?.remove();
        document.getElementById(`board-row-${cardId}`)?.remove();
        updateTexts('board-count-', counts);
        updateTexts('board-history-', history);
        recountCells([sourceCell]);
        document.dispatchEvent(
            new CustomEvent('board:placed', {
                detail: { cardId, removed: true },
            }),
        );

        return;
    }

    if (stream.hasAttribute('data-lane-head')) {
        placeLaneHead(stream, cardId, counts, history);

        return;
    }

    const group = container(stream, cardId);
    const list = document.querySelector('.lp-board-list');
    const after = anchor(stream.dataset.after, 'board-card-');
    const rowAfter = anchor(stream.dataset.rowAfter, 'board-row-');
    const stale = after && after.parentElement !== group;
    if (
        !group ||
        !list ||
        after === undefined ||
        rowAfter === undefined ||
        stale
    ) {
        document.dispatchEvent(
            new CustomEvent('board:place-missed', { detail: { cardId } }),
        );

        return;
    }

    const content = stream.querySelector('template').content.cloneNode(true);
    const rowAnchor = rowAfter ?? list.querySelector('.lp-board-list__header');
    place(
        `board-card-${cardId}`,
        content.querySelector('.lp-board-card'),
        (node) => (after ? after.after(node) : group.prepend(node)),
    );
    place(
        `board-row-${cardId}`,
        content.querySelector('.lp-board-list__row'),
        (node) => (rowAnchor ? rowAnchor.after(node) : list.prepend(node)),
    );
    updateTexts('board-count-', counts);
    updateTexts('board-history-', history);
    recountCells([sourceCell, group]);

    document.getElementById(`board-card-${cardId}`).dispatchEvent(
        new CustomEvent('board:placed', {
            bubbles: true,
            detail: { cardId },
        }),
    );
}

/**
 * The column group on a board with no lane, or the lane cell on a board with
 * lanes. A stream and a page that disagree on lanes get null. A lane epic has
 * no card face on a board with lanes, so its face gets null too.
 */
function container(stream, cardId) {
    const column = stream.dataset.columnId;
    const lane = stream.dataset.lane;
    const hasLanes = document.querySelector('.lp-board-lanes') !== null;
    if (lane === undefined) {
        return hasLanes
            ? null
            : document.getElementById(`board-group-${column}`);
    }
    if (!hasLanes || document.getElementById(`board-lane-${cardId}`)) {
        return null;
    }

    return document.getElementById(`board-cell-${lane}-${column}`);
}

/** Morphs the head of a lane epic, and places its list row. */
function placeLaneHead(stream, cardId, counts, history) {
    const section = document.getElementById(`board-lane-${cardId}`);
    const list = document.querySelector('.lp-board-list');
    const rowAfter = anchor(stream.dataset.rowAfter, 'board-row-');
    const head = section?.querySelector('.lp-board-lane__head');
    if (!head || !list || rowAfter === undefined) {
        document.dispatchEvent(
            new CustomEvent('board:place-missed', { detail: { cardId } }),
        );

        return;
    }

    const content = stream.querySelector('template').content.cloneNode(true);
    const rowAnchor = rowAfter ?? list.querySelector('.lp-board-list__header');
    // The lane controller owns the collapse, and the fresh head always says expanded.
    const toggle = head.querySelector('[aria-expanded]');
    const expanded = toggle?.getAttribute('aria-expanded');
    morphElements(head, content.querySelector('.lp-board-lane__head'));
    if (toggle && expanded !== null) {
        toggle.setAttribute('aria-expanded', expanded);
    }
    place(
        `board-row-${cardId}`,
        content.querySelector('.lp-board-list__row'),
        (node) => (rowAnchor ? rowAnchor.after(node) : list.prepend(node)),
    );
    updateTexts('board-count-', counts);
    updateTexts('board-history-', history);

    section.dispatchEvent(
        new CustomEvent('board:placed', {
            bubbles: true,
            detail: { cardId },
        }),
    );
}

/** Writes the number of cards each lane cell holds into the count of its column head. */
function recountCells(cells) {
    new Set(cells).forEach((cell) => {
        if (!cell?.classList.contains('lp-board-lane__cell')) {
            return;
        }
        const count = cell
            .closest('.lp-board-lane__column')
            ?.querySelector('[data-cell-count]');
        if (count) {
            count.textContent = String(
                cell.querySelectorAll(':scope > .lp-board-card').length,
            );
        }
    });
}

/** The element an id names, null for no id, undefined when the page lacks it. */
function anchor(id, prefix) {
    if (!id) {
        return null;
    }

    return document.getElementById(prefix + id) ?? undefined;
}

function place(id, fresh, insert) {
    const current = document.getElementById(id);
    if (!current) {
        insert(fresh);

        return;
    }
    insert(current);
    morphElements(current, fresh);
}

/** Writes each column's value into the element `prefix + columnId`, when the page has it. */
function updateTexts(prefix, byColumn) {
    Object.entries(byColumn).forEach(([columnId, text]) => {
        const element = document.getElementById(prefix + columnId);
        if (element) {
            element.textContent = String(text);
        }
    });
}

StreamActions['board-place'] = function boardPlace() {
    placeCard(this);
};
