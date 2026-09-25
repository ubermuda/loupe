import { StreamActions, morphElements } from '@hotwired/turbo';

/**
 * The board-place stream action puts one card, and its list row, where the
 * server says it sits. A removed card comes as data-removed with an empty
 * template, so every change carries the column counts through one path.
 * A page that lacks the column or an anchor, or shows the anchor card in
 * another column, changes nothing and reports board:place-missed, because a
 * guessed position would show a wrong order.
 */
export function placeCard(stream) {
    const cardId = stream.getAttribute('target').replace(/^board-card-/, '');
    const counts = JSON.parse(stream.dataset.counts || '{}');
    const history = JSON.parse(stream.dataset.history || '{}');

    if (stream.hasAttribute('data-removed')) {
        document.getElementById(`board-card-${cardId}`)?.remove();
        document.getElementById(`board-row-${cardId}`)?.remove();
        updateTexts('board-count-', counts);
        updateTexts('board-history-', history);
        document.dispatchEvent(
            new CustomEvent('board:placed', {
                detail: { cardId, removed: true },
            }),
        );

        return;
    }

    const group = document.getElementById(
        `board-group-${stream.dataset.columnId}`,
    );
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

StreamActions['board-place'] = function boardPlace() {
    placeCard(this);
};
