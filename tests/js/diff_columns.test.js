/** @vitest-environment jsdom */
import { afterEach, expect, it } from 'vitest';
import {
    alignRows,
    tableRowPairs,
} from '../../assets/controllers/diff_columns_controller.js';

afterEach(() => {
    document.body.replaceChildren();
});

const table = (rows) =>
    `<table><tbody>${rows.map((row) => `<tr data-row="${row}"><td>${row}</td></tr>`).join('')}</tbody></table>`;

function mount(cells) {
    document.body.innerHTML = `<div class="lp-diff-columns">${cells
        .map(
            ([side, row, html]) =>
                `<div class="lp-diff-columns__cell" data-diff-side="${side}" data-diff-row="${row}">${html}</div>`,
        )
        .join('')}</div>`;

    return document.querySelector('.lp-diff-columns');
}

const names = (pairs) =>
    pairs.map(([oldRow, newRow]) => [oldRow.dataset.row, newRow.dataset.row]);

it('pairs the rows of the two tables in one grid row by position', () => {
    const grid = mount([
        ['old', 1, table(['a1', 'a2'])],
        ['new', 1, table(['b1', 'b2', 'b3'])],
    ]);

    expect(names(tableRowPairs(grid))).toEqual([
        ['a1', 'b1'],
        ['a2', 'b2'],
    ]);
});

it('pairs the second table with the second table', () => {
    const grid = mount([
        ['old', 1, table(['a1']) + '<p>Between.</p>' + table(['c1'])],
        ['new', 1, table(['b1']) + table(['d1'])],
    ]);

    expect(names(tableRowPairs(grid))).toEqual([
        ['a1', 'b1'],
        ['c1', 'd1'],
    ]);
});

it('pairs nothing when only one side holds a table', () => {
    const grid = mount([
        ['old', 1, table(['a1'])],
        ['new', 1, '<p>Prose now.</p>'],
        ['old', 2, '<p>Gone.</p>'],
        ['new', 2, table(['b1'])],
    ]);

    expect(tableRowPairs(grid)).toEqual([]);
});

it('keeps each grid row to its own cells', () => {
    const grid = mount([
        ['old', 1, table(['a1'])],
        ['new', 1, '<p>Prose.</p>'],
        ['old', 2, '<p>Prose.</p>'],
        ['new', 2, table(['b1'])],
        ['old', 3, table(['c1'])],
        ['new', 3, table(['d1'])],
    ]);

    expect(names(tableRowPairs(grid))).toEqual([['c1', 'd1']]);
});

it('gives both rows of a pair the taller height', () => {
    const grid = mount([
        ['old', 1, table(['a1', 'a2'])],
        ['new', 1, table(['b1', 'b2'])],
    ]);
    const heights = { a1: 40, b1: 90, a2: 30, b2: 20 };
    const measured = [];

    alignRows(tableRowPairs(grid), true, (row) => {
        measured.push(row.style.height);
        return heights[row.dataset.row];
    });

    expect(
        [...grid.querySelectorAll('tr')].map(
            (row) => `${row.dataset.row}=${row.style.height}`,
        ),
    ).toEqual(['a1=90px', 'a2=30px', 'b1=90px', 'b2=30px']);
    // Measured with the heights cleared, so a narrower window can shrink a row.
    expect(measured.every((height) => height === '')).toBe(true);
});

it('clears the heights when the columns stack', () => {
    const grid = mount([
        ['old', 1, table(['a1'])],
        ['new', 1, table(['b1'])],
    ]);
    const pairs = tableRowPairs(grid);
    alignRows(pairs, true, () => 50);

    alignRows(pairs, false, () => 50);

    expect(
        [...grid.querySelectorAll('tr')].map((row) => row.style.height),
    ).toEqual(['', '']);
});
