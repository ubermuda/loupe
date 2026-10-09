/**
 * Browser coverage for the live board refresh: a column added, renamed,
 * reordered or deleted from board settings in one browser shows in a second
 * browser on the same board, with no navigation there. The hub delivers the
 * nudge, so the run needs a Mercure hub the browser can reach.
 */

import { test, expect, type Page } from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBoardRefresh1!';
const COLUMN = 'section.lp-board__column';

// Each test signs in two browsers beside three other workers, which fills the
// default budget on a loaded runner.
test.slow();

async function openBoard(page: Page, boardUrl: string): Promise<void> {
    await page.goto(boardUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(page.locator('[data-board-live-connected]')).toHaveCount(1, {
        timeout: 15000,
    });
}

/** Collects each later GET of the board page, which would be a whole board reload. */
function countBoardLoads(page: Page, boardUrl: string): string[] {
    const loads: string[] = [];
    page.on('request', (request) => {
        if (
            request.method() === 'GET' &&
            new URL(request.url()).pathname === boardUrl
        ) {
            loads.push(request.url());
        }
    });

    return loads;
}

test('a column renamed in one browser shows in another without a reload', async ({
    browser,
    request,
}) => {
    const email = `e2e+refresh+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Refresh User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const editor = await signedInPage(browser, email, PASSWORD);
    const seeded = await editor.request.post('/dev/seed/document', {
        form: { title: 'E2E Refresh Project', markdown: '# Refresh' },
    });
    expect(seeded.status()).toBe(201);
    const boardUrl = `/projects/${(await seeded.json()).projectId}/board`;

    const watcher = await signedInPage(browser, email, PASSWORD);
    await openBoard(editor, boardUrl);

    // The watcher's first hub request fails, so it renews its cookie through
    // the shared endpoint, CSRF included, before it connects.
    let hubRefused = false;
    await watcher.context().route(
        (url) => url.pathname === '/.well-known/mercure',
        (route) => {
            if (hubRefused) {
                return route.fallback();
            }
            hubRefused = true;
            return route.abort();
        },
    );
    const renewal = watcher.waitForResponse((response) =>
        response.url().endsWith('/mercure/authorize'),
    );
    await openBoard(watcher, boardUrl);
    const boardLoads = countBoardLoads(watcher, boardUrl);
    const renewed = await renewal;
    expect(renewed.status()).toBe(200);
    // The board topic, and the run topic of the card drawer the board hosts.
    const topics: string[] = (await renewed.json()).topics;
    expect(topics).toHaveLength(2);
    expect(topics.some((topic) => topic.endsWith('/board'))).toBe(true);
    expect(topics.some((topic) => topic.endsWith('/worker-runs'))).toBe(true);

    // The update keeps the toolbar, so the watcher's filter and view stay.
    const search = watcher.getByRole('searchbox', { name: 'Search cards' });
    await search.fill('no card has this title');
    await watcher.getByRole('button', { name: 'List', exact: true }).click();
    await expect(watcher.locator('.lp-board-list')).toBeVisible();

    // A full navigation would drop this marker, and an in-place update keeps it.
    await watcher.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });
    // A morph keeps the element of a column that did not change, and a replace does not.
    await watcher
        .locator(`${COLUMN}[data-column-slug="in-progress"]`)
        .evaluate((column) => {
            (column as unknown as { kept: boolean }).kept = true;
        });

    await editor.goto(boardUrl.replace(/\/board$/, '/settings/columns'));
    const settings = editor.locator('[data-board-column-settings]');
    await settings
        .getByRole('button', { name: 'Configure Next', exact: true })
        .click();
    const dialog = editor.locator('dialog[open]');
    await dialog.getByLabel('Column name', { exact: true }).fill('Up next');
    await dialog.getByRole('button', { name: 'Save column' }).click();
    await expect(
        settings.getByRole('heading', { name: 'Up next', exact: true }),
    ).toBeVisible({ timeout: 15_000 });

    await expect(
        watcher.locator(`${COLUMN}[data-column-slug="up-next"] h2`),
    ).toHaveText('Up next');
    await expect(search).toHaveValue('no card has this title');
    await expect(
        watcher.locator('[data-board-view-target="list"]'),
    ).toBeVisible();
    await expect(
        watcher.locator('[data-board-view-target="board"]'),
    ).toBeHidden();
    await expect(
        watcher.getByText('No cards match these filters.'),
    ).toBeVisible();
    expect(
        await watcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);
    expect(
        await watcher
            .locator(`${COLUMN}[data-column-slug="in-progress"]`)
            .evaluate(
                (column) => (column as unknown as { kept?: boolean }).kept,
            ),
    ).toBe(true);
    // The column change updates the structure in place, with no board reload.
    expect(boardLoads).toEqual([]);

    await editor.context().close();
    await watcher.context().close();
});

async function createCard(
    page: Page,
    projectId: string,
    title: string,
    column: string,
): Promise<void> {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('Column').selectOption({ label: column });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: title })).toBeVisible({
        timeout: 15_000,
    });
}

function slugs(page: Page): Promise<string[]> {
    return page
        .locator(COLUMN)
        .evaluateAll((columns) =>
            columns.map(
                (column) => column.getAttribute('data-column-slug') ?? '',
            ),
        );
}

test('columns added, reordered and deleted in one browser update another in place', async ({
    browser,
    request,
}) => {
    // Three edits and two card creations take about 89 of the 90 slow seconds on CI.
    test.setTimeout(test.info().timeout * 2);
    const email = `e2e+refresh+columns+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Refresh User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const editor = await signedInPage(browser, email, PASSWORD);
    const seeded = await editor.request.post('/dev/seed/document', {
        form: { title: 'E2E Refresh Columns', markdown: '# Columns' },
    });
    expect(seeded.status()).toBe(201);
    const projectId: string = (await seeded.json()).projectId;
    const boardUrl = `/projects/${projectId}/board`;
    // The board draws no Backlog, so the card it watches sits in a drawn column.
    await createCard(editor, projectId, 'Kept', 'In progress');
    await createCard(editor, projectId, 'Moved', 'Next');

    const watcher = await signedInPage(browser, email, PASSWORD);
    await openBoard(watcher, boardUrl);
    const boardLoads = countBoardLoads(watcher, boardUrl);
    const kept = watcher.locator('.lp-board-card[data-card-title="Kept"]');
    await expect(kept).toBeVisible();
    // A re-render replaces the card element, and the new one lacks this property.
    await kept.evaluate((card) => {
        (card as unknown as { kept: boolean }).kept = true;
    });

    await editor.goto(`/projects/${projectId}/settings/columns`);
    const settings = editor.locator('[data-board-column-settings]');
    await settings
        .getByRole('button', { name: 'Add a column', exact: true })
        .click();
    const addDialog = editor.getByRole('dialog');
    await addDialog.getByRole('textbox').fill('Parked');
    await addDialog
        .getByRole('button', { name: 'Add column', exact: true })
        .click();
    await expect(
        settings.getByRole('heading', { name: 'Parked', exact: true }),
    ).toBeVisible({ timeout: 15_000 });
    await expect
        .poll(() => slugs(watcher), { timeout: 15_000 })
        .toEqual(['next', 'in-progress', 'done', 'parked']);
    await expect(kept).toHaveJSProperty('kept', true);

    await settings
        .locator('[data-column-id]')
        .filter({
            has: editor.getByRole('heading', { name: 'Parked', exact: true }),
        })
        .getByRole('button', { name: 'Move up', exact: true })
        .click();
    await expect(settings.locator('.lp-settings-column__name code')).toHaveText(
        ['next', 'in-progress', 'parked', 'done'],
        { timeout: 15_000 },
    );
    await expect
        .poll(() => slugs(watcher), { timeout: 15_000 })
        .toEqual(['next', 'in-progress', 'parked', 'done']);
    await expect(kept).toHaveJSProperty('kept', true);

    await settings
        .locator('[data-column-slug="next"]')
        .getByRole('button', { name: 'Delete Next', exact: true })
        .click();
    const deleteDialog = editor.locator('dialog[open]');
    await deleteDialog
        .getByLabel('Move the cards to')
        .selectOption({ label: 'In progress' });
    await deleteDialog
        .getByRole('button', { name: 'Move the cards and delete' })
        .click();
    await expect(editor.getByText('moved its card')).toBeVisible({
        timeout: 15_000,
    });
    await expect
        .poll(() => slugs(watcher), { timeout: 15_000 })
        .toEqual(['in-progress', 'parked', 'done']);
    await expect(
        watcher.locator(
            `${COLUMN}[data-column-slug="in-progress"] .lp-board-card[data-card-title="Moved"]`,
        ),
    ).toBeVisible({ timeout: 15_000 });
    await expect(kept).toHaveJSProperty('kept', true);
    expect(boardLoads).toEqual([]);

    await editor.context().close();
    await watcher.context().close();
});
