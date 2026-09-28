import { StreamActions, morphElements } from '@hotwired/turbo';

/**
 * The board-place stream action puts one card, and its list row, where the
 * server says it sits. A removed card comes as data-removed with an empty
 * template, so every change carries the column counts through one path.
 * On a board with lanes the group is the cell of the card's lane and column.
 * A page that lacks the group or an anchor, or shows the anchor card in
 * another group, changes nothing and reports board:place-missed, because a
 * guessed position would show a wrong order. A lane head, on the page or in
 * the stream, also misses, because the placement draws cards only.
 */
export function placeCard(stream) {
    const cardId = stream.getAttribute('target').replace(/^board-card-/, '');
    const counts = JSON.parse(stream.dataset.counts || '{}');
    const history = JSON.parse(stream.dataset.history || '{}');
    const historyTotals = JSON.parse(stream.dataset.historyTotals || '{}');
    const laneCounts = JSON.parse(stream.dataset.laneCounts || '{}');
    const missed = () =>
        document.dispatchEvent(
            new CustomEvent('board:place-missed', { detail: { cardId } }),
        );

    if (
        stream.hasAttribute('data-lane-epic') ||
        document.querySelector(
            `.lp-board-lane[data-lane="${CSS.escape(cardId)}"]`,
        )
    ) {
        missed();

        return;
    }

    if (stream.hasAttribute('data-removed')) {
        document.getElementById(`board-card-${cardId}`)?.remove();
        document.getElementById(`board-row-${cardId}`)?.remove();
        updateTexts('board-count-', counts);
        updateTexts('board-history-', history);
        updateHistoryTotals(historyTotals);
        updateLaneCounts(laneCounts);
        document.dispatchEvent(
            new CustomEvent('board:placed', {
                detail: { cardId, removed: true },
            }),
        );

        return;
    }

    const group = stream.dataset.lane
        ? laneCell(stream.dataset.lane, stream.dataset.columnId)
        : document.getElementById(`board-group-${stream.dataset.columnId}`);
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
        missed();

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
    updateHistoryTotals(historyTotals);
    updateLaneCounts(laneCounts);

    document.getElementById(`board-card-${cardId}`).dispatchEvent(
        new CustomEvent('board:placed', {
            bubbles: true,
            detail: { cardId },
        }),
    );
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

/** Keeps the total each history link shows, for the reconnect catch-up to compare. */
function updateHistoryTotals(byColumn) {
    Object.entries(byColumn).forEach(([columnId, total]) => {
        const element = document.getElementById(`board-history-${columnId}`);
        if (element) {
            element.dataset.historyTotal = String(total);
        }
    });
}

function laneCell(lane, columnId) {
    return document.querySelector(
        `.lp-board-lane__cell[data-lane="${CSS.escape(lane)}"][data-column="${CSS.escape(columnId)}"]`,
    );
}

/** Writes each lane cell's count into the head of the column section that holds the cell. */
function updateLaneCounts(byLane) {
    Object.entries(byLane).forEach(([lane, byColumn]) => {
        Object.entries(byColumn).forEach(([columnId, count]) => {
            const element = laneCell(
                lane,
                columnId,
            )?.parentElement.querySelector('.lp-board__column-count');
            if (element) {
                element.textContent = String(count);
            }
        });
    });
}

StreamActions['board-place'] = function boardPlace() {
    placeCard(this);
};
