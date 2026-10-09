/**
 * The diff is a mode of the review page: the byline leads into it, a compare
 * bar steps through it, and the panels follow the view. Scroll, panel state and
 * the row heights of the side by side view are all set by Stimulus, so PHPUnit
 * cannot reach them.
 */

import { expect, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';
import { PANELS_KEY, panelButton } from './panels';

// Every view switch is a Turbo visit, which a loaded dev server answers in
// more than the 5 s default.
const VISIT = { timeout: coverageScaled(15_000) };

const test = createTest({
    email: 'e2e-diff-mode@example.com',
    password: 'E2eDiffMode1!',
});

// Split over workers, this file would register one fixed account twice at once.
test.describe.configure({ mode: 'default' });

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
});

// Three changes, each kept apart by unchanged text, and a table whose edited
// cell wraps to more lines in the newer column.
const VERSION_ONE = [
    '## Plan',
    '',
    'The rollout takes one step.',
    '',
    'The team runs it on a Friday.',
    '',
    '| Option | Cons |',
    '|---|---|',
    '| Ship now | Risky |',
    '| Ship later | Slow |',
    '',
    '## Owner',
    '',
    'The platform team owns this plan.',
].join('\n');

const VERSION_TWO = [
    '## Plan',
    '',
    'The rollout takes three steps.',
    '',
    'The team runs it on a Friday.',
    '',
    '| Option | Cons |',
    '|---|---|',
    '| Ship now | Risky, because the release train has no rollback rehearsal yet and the queue backs up within the hour |',
    '| Ship later | Slow |',
    '',
    '## Owner',
    '',
    'The infrastructure team owns this plan.',
].join('\n');

async function seed(page: Page, revisions: string[]): Promise<string> {
    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Diff Mode Plan',
            markdown: VERSION_ONE,
            revisions: JSON.stringify(revisions),
        },
    });
    expect(seeded.status()).toBe(201);
    const { projectId, documentId } = await seeded.json();

    return `/projects/${projectId}/documents/${documentId}/review`;
}

test('S7: the byline opens the diff, and next steps through the changes', async ({
    page,
}) => {
    test.slow();
    await page.setViewportSize({ width: 1440, height: 900 });
    const reviewPath = await seed(page, []);
    await page.goto(reviewPath);
    await page.evaluate(
        (key) => window.localStorage.removeItem(key),
        PANELS_KEY,
    );

    // A verdict on v1 is what makes v1 the version this reviewer last saw.
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const finish = page.getByRole('dialog', { name: 'Finish review' });
    await finish.getByRole('radio', { name: 'Approve', exact: true }).check();
    await finish.getByRole('button', { name: 'Submit review' }).click();
    await expect(page.locator('.lp-verdict-chip--approved')).toBeVisible({
        timeout: 20000,
    });

    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    const revise = page.getByRole('dialog', { name: 'Revise document' });
    await revise.getByLabel('Markdown', { exact: true }).fill(VERSION_TWO);
    await revise
        .getByLabel('Revision note', { exact: true })
        .fill('Phase the rollout.');
    await revise.getByRole('button', { name: 'Save new version' }).click();
    await expect(page.locator('.lp-review-doc__version')).toHaveText('v2', {
        timeout: 20000,
    });

    await page
        .locator('.lp-review-doc__byline')
        .getByRole('link', { name: /New since v1/ })
        .click();
    await expect(page).toHaveURL(`${reviewPath}/diff/1/2`, VISIT);

    // The page keeps its chrome: the compare bar replaces the view tabs, and
    // the Outline opens to count the changes of each section.
    const bar = page.locator('.lp-diff-bar');
    await expect(
        bar.getByRole('link', { name: 'Return to document' }),
    ).toBeVisible();
    await expect(page.locator('.lp-review-workspace-nav')).toHaveCount(0);
    await expect(page.locator('#review-panel-outline')).toBeVisible();
    await expect(
        page.locator('#review-panel-outline .lp-review-contents__changes'),
    ).toHaveText(['2 changes', '1 change']);

    const counter = page.locator('.lp-diff-nav__count');
    await expect(counter).toHaveText('3 changes');

    await page.emulateMedia({ reducedMotion: 'reduce' });
    const next = bar.getByRole('button', { name: 'Next change' });
    await next.click();
    await expect(counter).toHaveText('1 of 3 changes');
    await next.click();
    await expect(counter).toHaveText('2 of 3 changes');
    await expect(page.locator('#diff-hunk-2')).toBeInViewport();

    // Opening the Outline here did not change the stored choice, so the
    // document opens with Decisions alone again.
    expect(
        await page.evaluate(
            (key) => window.localStorage.getItem(key),
            PANELS_KEY,
        ),
    ).toBeNull();
    await bar.getByRole('link', { name: 'Return to document' }).click();
    await expect(page).toHaveURL(reviewPath, VISIT);
    await expect(page.locator('#review-panel-outline')).toBeHidden();
});

test('S8: side by side hides the panels and lines up the table rows', async ({
    page,
}) => {
    test.slow();
    await page.setViewportSize({ width: 1440, height: 900 });
    const reviewPath = await seed(page, [VERSION_TWO]);
    await page.goto(`${reviewPath}/diff/1/2`);
    await page.evaluate(
        (key) => window.localStorage.removeItem(key),
        PANELS_KEY,
    );

    await page
        .locator('.lp-diff-views')
        .getByRole('link', { name: 'Side by side' })
        .click();
    await expect(page).toHaveURL(
        `${reviewPath}/diff/1/2?view=side-by-side`,
        VISIT,
    );

    const grid = page.locator('.lp-diff-columns');
    await expect(grid).toBeVisible();
    await expect(page.locator('.lp-review-panel:visible')).toHaveCount(0);
    await expect(panelButton(page, 'Outline')).toHaveAttribute(
        'aria-pressed',
        'false',
    );

    // Each table row stands level with its pair, though the newer cell wraps.
    const rowTops = () =>
        page.evaluate(() =>
            ['old', 'new'].map((side) =>
                [
                    ...document.querySelectorAll(
                        `.lp-diff-columns [data-diff-side="${side}"] tr`,
                    ),
                ].map((row) => Math.round(row.getBoundingClientRect().top)),
            ),
        );
    await expect
        .poll(async () => {
            const [old, current] = await rowTops();
            return old.length > 0 && old.join() === current.join();
        })
        .toBe(true);

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.getByRole('button', { name: 'Next change' }).click();
    await expect(page.locator('.lp-diff-nav__count')).toHaveText(
        '1 of 3 changes',
    );

    // The toolbar brings a panel back as a column beside the versions, which
    // shrink rather than slide under it.
    const before = await grid.boundingBox();
    await panelButton(page, 'Outline').click();
    const outline = page.locator('#review-panel-outline');
    await expect(outline).toBeVisible();
    await expect
        .poll(async () => (await grid.boundingBox())!.width)
        .toBeLessThan(before!.width);
    const gridBox = (await grid.boundingBox())!;
    const panelBox = (await outline.boundingBox())!;
    expect(gridBox.x + gridBox.width).toBeLessThanOrEqual(panelBox.x);

    // The narrower columns wrap more, and the rows level again.
    await expect
        .poll(async () => {
            const [old, current] = await rowTops();
            return old.join() === current.join();
        })
        .toBe(true);
    expect(
        await page.evaluate(
            (key) => window.localStorage.getItem(key),
            PANELS_KEY,
        ),
    ).toBeNull();
});
