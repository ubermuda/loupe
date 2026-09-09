/**
 * The third view of a comparison: the two versions in two columns, paired block
 * by block.
 *
 * The pairing is server-side, and the alignment is one CSS grid, so neither is
 * reachable from PHPUnit. Geometry is read against `.lp-main`, the app's scroll
 * container, because that is where a column wider than the screen shows up.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'E2eSideBySide1!';

const VERSION_ONE = [
    '# Rollout plan',
    '',
    'The rollout takes one step, and the team runs it on a Friday.',
    '',
    '## Risk',
    '',
    'The risk is low, because the change is small.',
    '',
    '## Rollback',
    '',
    'Revert the release tag and redeploy the previous image.',
].join('\n');

const VERSION_TWO = [
    '# Rollout plan',
    '',
    'The rollout takes three steps, and the team runs it on a Tuesday.',
    '',
    '## Risk',
    '',
    'The risk is low, because the change is small.',
    '',
    '## Monitoring',
    '',
    'Watch the worker queue depth for one hour after the release.',
].join('\n');

const CELL = '.lp-diff-columns__cell';
const VOID_CELL = '.lp-diff-columns__cell--void';
const MARGIN = '.lp-review-margin';
const VIEWS = '.lp-diff-views';

async function signIn(page: Page, email: string): Promise<void> {
    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Side By Side', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', {
        timeout: coverageScaled(15000),
    });
    await suppressToolbar(page);
    await suppressWidget(page);
}

async function seedComparison(page: Page): Promise<string> {
    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Side By Side Plan',
            markdown: VERSION_ONE,
            revisions: JSON.stringify([VERSION_TWO]),
        },
    });
    expect(seeded.status()).toBe(201);
    const { projectId, documentId } = await seeded.json();

    return `/projects/${projectId}/documents/${documentId}/review`;
}

/** Grid geometry of every cell, keyed by the pair it belongs to. */
async function readCells(page: Page) {
    await page.evaluate(
        () => new Promise((resolve) => requestAnimationFrame(resolve)),
    );

    return page.evaluate(() => {
        return [...document.querySelectorAll('.lp-diff-columns__cell')].map(
            (cell) => {
                const rect = cell.getBoundingClientRect();
                const prose = cell.querySelector('.lp-diff-doc');

                return {
                    row: cell.getAttribute('data-diff-row'),
                    isVoid: cell.classList.contains(
                        'lp-diff-columns__cell--void',
                    ),
                    top: Math.round(rect.top),
                    left: Math.round(rect.left),
                    text: (prose?.textContent ?? '').trim(),
                };
            },
        );
    });
}

/** Anything under the block whose content is wider than its own box. */
async function contentEscapingItsBox(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const block = document.querySelector('.lp-review-block')!;
        const escaped: string[] = [];
        for (const element of [block, ...block.querySelectorAll('*')]) {
            if (
                element.scrollWidth > element.clientWidth + 1 &&
                getComputedStyle(element).overflowX === 'visible'
            ) {
                escaped.push(
                    `${element.tagName}.${String(element.className).slice(0, 40)} scrollWidth=${element.scrollWidth} clientWidth=${element.clientWidth}`,
                );
            }
        }
        return escaped;
    });
}

test('the two columns pair the blocks and drop the comment rail', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await signIn(page, `e2e-sbs-pair-${RUN}@example.com`);
    const reviewPath = await seedComparison(page);

    await page.goto(`${reviewPath}/diff/1/2`);
    // The Document view is where a comparison starts, and it carries the rail.
    await expect(page.locator(MARGIN)).toHaveCount(1);

    await page
        .locator(VIEWS)
        .getByRole('link', { name: 'Side by side' })
        .click();
    await expect(page).toHaveURL(`${reviewPath}/diff/1/2?view=side-by-side`);
    await expect(page.locator('.lp-diff-views__link[aria-current]')).toHaveText(
        'Side by side',
    );

    const grid = page.locator('.lp-diff-columns');
    await expect(grid).toBeVisible();
    await expect(grid.locator('.lp-diff-columns__title')).toHaveText([
        'Version 1',
        'Version 2',
    ]);

    const cells = await readCells(page);
    expect(cells.length).toBeGreaterThan(0);
    expect(cells.length % 2).toBe(0);

    // Both cells of a pair carry the same row number and sit at the same height,
    // which is the alignment the view exists for.
    for (let index = 0; index < cells.length; index += 2) {
        const [left, right] = [cells[index], cells[index + 1]];
        expect(right.row).toBe(left.row);
        expect(right.top).toBe(left.top);
        expect(right.left).toBeGreaterThan(left.left);
    }

    // The reworded paragraph reads whole on both sides, each with its own wording.
    const reworded = cells.filter((cell) =>
        cell.text.includes('the team runs it on a'),
    );
    expect(reworded).toHaveLength(2);
    expect(reworded[0].text).toContain('one step');
    expect(reworded[0].text).not.toContain('three steps');
    expect(reworded[1].text).toContain('three steps');
    expect(reworded[1].text).not.toContain('one step');
    expect(reworded[0].row).toBe(reworded[1].row);

    // The removed section leaves a slot opposite it rather than closing up.
    const removed = cells.find((cell) => cell.text.includes('Rollback'));
    expect(removed).toBeDefined();
    const opposite = cells.find(
        (cell) => cell.row === removed!.row && cell !== removed,
    );
    expect(opposite!.isVoid).toBe(true);
    expect(opposite!.top).toBe(removed!.top);

    const added = cells.find((cell) => cell.text.includes('Monitoring'));
    expect(added).toBeDefined();
    expect(
        cells.find((cell) => cell.row === added!.row && cell !== added)!.isVoid,
    ).toBe(true);

    await expect(page.locator(VOID_CELL)).toHaveCount(2);

    // Commenting is off, and the page says so rather than leaving the reader to
    // notice a missing column.
    await expect(page.locator(MARGIN)).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Add general comment' }),
    ).toHaveCount(0);
    await expect(page.locator('#diff-columns-notice')).toContainText(
        'Comments are hidden here',
    );

    // The block widens for the second reading measure, and the chrome above it
    // still sits inside that width.
    const widths = await page.evaluate(() => {
        const block = document.querySelector('.lp-review-block')!;
        const bar = document.querySelector('.lp-diff-bar')!;
        const chip = document.querySelector('.lp-doc-meta__compare')!;
        return {
            block: Math.round(block.getBoundingClientRect().width),
            barRight: Math.round(bar.getBoundingClientRect().right),
            blockRight: Math.round(block.getBoundingClientRect().right),
            chipLeft: Math.round(chip.getBoundingClientRect().left),
            blockLeft: Math.round(block.getBoundingClientRect().left),
        };
    });
    expect(widths.block).toBe(1120);
    expect(widths.barRight).toBeLessThanOrEqual(widths.blockRight + 1);
    expect(widths.chipLeft).toBeGreaterThanOrEqual(widths.blockLeft - 1);

    // Going back restores the rail, so the reader loses nothing by looking.
    // Scoped: the sidebar and the crumbs both carry a Documents link.
    await page
        .locator(VIEWS)
        .getByRole('link', { name: 'Document', exact: true })
        .click();
    await expect(page).toHaveURL(`${reviewPath}/diff/1/2?view=rendered`);
    await expect(page.locator(MARGIN)).toHaveCount(1);
    await expect(
        page.getByRole('button', { name: 'Add general comment' }),
    ).toBeVisible();
    await expect(page.locator('#diff-columns-notice')).toHaveCount(0);
});

test('the jump controls still walk the changes across the two columns', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await signIn(page, `e2e-sbs-jump-${RUN}@example.com`);
    const reviewPath = await seedComparison(page);

    await page.goto(`${reviewPath}/diff/1/2?view=side-by-side`);

    const counter = page.locator('.lp-diff-nav__count');
    await expect(counter).toHaveText('3 changes');

    // Each mark belongs to one column, so no jump target is numbered twice.
    const ids = await page.evaluate(() =>
        [...document.querySelectorAll('[data-diff-navigation-target="hunk"]')]
            .map((hunk) => hunk.id)
            .sort(),
    );
    expect(ids).toEqual(['diff-hunk-1', 'diff-hunk-2', 'diff-hunk-3']);

    // An unchanged heading reaches both cells, and only the newer one keeps the
    // id, or a fragment would land in whichever column came first.
    const duplicated = await page.evaluate(() => {
        const seen = new Map<string, number>();
        for (const element of document.querySelectorAll(
            '.lp-diff-columns [id]',
        )) {
            seen.set(element.id, (seen.get(element.id) ?? 0) + 1);
        }
        return [...seen].filter(([, count]) => count > 1).map(([id]) => id);
    });
    expect(duplicated).toEqual([]);
    // The unchanged heading is in both cells and carries its id in one, so the
    // check above is about a real collision rather than an empty page.
    await expect(
        page.locator('.lp-diff-columns [id="heading-risk"]'),
    ).toHaveCount(1);

    await page.getByRole('button', { name: 'Next change' }).click();
    await expect(counter).toHaveText('Change 1 of 3');
    await expect(page.locator('.lp-diff__hunk--current')).toHaveCount(1);
});

test('the columns stack at a phone width instead of overflowing', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, `e2e-sbs-phone-${RUN}@example.com`);
    const reviewPath = await seedComparison(page);

    await page.goto(`${reviewPath}/diff/1/2?view=side-by-side`);
    await expect(page.locator('.lp-diff-columns')).toBeVisible();

    const stacked = await page.evaluate(() => {
        const grid = document.querySelector('.lp-diff-columns')!;
        const main = document.querySelector('.lp-main')!;
        return {
            columns:
                getComputedStyle(grid).gridTemplateColumns.split(' ').length,
            mainScrollWidth: main.scrollWidth,
            mainClientWidth: main.clientWidth,
        };
    });
    expect(stacked.columns).toBe(1);
    expect(stacked.mainScrollWidth).toBeLessThanOrEqual(
        stacked.mainClientWidth,
    );
    expect(await contentEscapingItsBox(page)).toEqual([]);

    // Stacked, a cell carries its own version name, since the column headings
    // above no longer sit over one column each.
    const labels = page.locator(`${CELL} .lp-diff-columns__label`).first();
    await expect(labels).toBeVisible();
    await expect(page.locator('.lp-diff-columns__title').first()).toBeHidden();
});
