import { Controller } from '@hotwired/stimulus';

/**
 * Keeps the rows of an edited table level in the side by side view. The grid
 * pairs blocks, so a table is one grid row, and its inner rows drift once a
 * cell wraps to more lines on one side. Rows pair by position, so a row added
 * in the middle shifts the rows under it.
 *
 * Usage: <div class="lp-diff-columns" data-controller="diff-columns">
 */
export default class extends Controller {
    connect() {
        this.width = null;
        this.frame = null;
        this.observer = new ResizeObserver(([entry]) => {
            if (entry.contentRect.width === this.width) {
                return;
            }
            this.width = entry.contentRect.width;
            this.schedule();
        });
        this.observer.observe(this.element);
        // An image that loads late grows its row without changing the width.
        this.onLoad = () => this.schedule();
        this.element.addEventListener('load', this.onLoad, true);
    }

    disconnect() {
        this.observer.disconnect();
        this.element.removeEventListener('load', this.onLoad, true);
        window.cancelAnimationFrame(this.frame);
    }

    // A frame later: a resize inside the callback re-enters it.
    schedule() {
        window.cancelAnimationFrame(this.frame);
        this.frame = window.requestAnimationFrame(() => this.align());
    }

    align() {
        const sideBySide =
            getComputedStyle(this.element).gridTemplateColumns.split(' ')
                .length > 1;
        alignRows(tableRowPairs(this.element), sideBySide);
    }
}

/**
 * Each row of a table in an older cell, with the row at the same position in
 * the table at the same position in the newer cell of that grid row.
 */
export function tableRowPairs(container) {
    const pairs = [];
    for (const oldCell of container.querySelectorAll(
        '[data-diff-side="old"]',
    )) {
        const newCell = container.querySelector(
            `[data-diff-side="new"][data-diff-row="${oldCell.dataset.diffRow}"]`,
        );
        if (newCell === null) {
            continue;
        }
        const oldTables = oldCell.querySelectorAll('table');
        const newTables = newCell.querySelectorAll('table');
        for (
            let table = 0;
            table < Math.min(oldTables.length, newTables.length);
            table++
        ) {
            const oldRows = oldTables[table].rows;
            const newRows = newTables[table].rows;
            for (
                let row = 0;
                row < Math.min(oldRows.length, newRows.length);
                row++
            ) {
                pairs.push([oldRows[row], newRows[row]]);
            }
        }
    }

    return pairs;
}

/**
 * Sets both rows of each pair to the taller one. A table row reads `height`
 * as a minimum and ignores `min-height`. Every height is cleared and measured
 * before any is written, so a wider window can shrink a row again.
 */
export function alignRows(
    pairs,
    sideBySide,
    measure = (row) => row.getBoundingClientRect().height,
) {
    for (const [oldRow, newRow] of pairs) {
        oldRow.style.height = '';
        newRow.style.height = '';
    }
    if (!sideBySide) {
        return;
    }
    const heights = pairs.map(([oldRow, newRow]) =>
        Math.max(measure(oldRow), measure(newRow)),
    );
    pairs.forEach(([oldRow, newRow], index) => {
        oldRow.style.height = `${heights[index]}px`;
        newRow.style.height = `${heights[index]}px`;
    });
}
