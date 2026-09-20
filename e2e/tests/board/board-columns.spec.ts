/**
 * Browser coverage for editing a board's columns: add a column and configure
 * one from board settings, rename one with its slug preview, drag a header to
 * reorder, and delete a column whose cards move to a target. Each change is
 * read back after a reload, because the server is what decides.
 */

import {
    test as base,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { expectFilterFocusRingVisible } from '../helpers';

const RUN = Date.now();
const PASSWORD = 'E2eBoardColumns1!';

const COLUMN = '[data-board-columns-target="column"]';

// Under a loaded run, the POST and the redirected GET take longer than the 5 s default.
const ROUND_TRIP = { timeout: process.env.COVERAGE ? 20_000 : 15_000 };

async function setBoardFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Columns User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

async function seedProject(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Columns Project', markdown: '# Columns' },
    });
    expect(response.status()).toBe(201);

    return (await response.json()).projectId as string;
}

async function createCard(
    page: Page,
    projectId: string,
    title: string,
    column: string,
): Promise<void> {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('Column').selectOption({ label: column });
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.request().method() === 'POST' &&
                new URL(response.url()).pathname.endsWith('/board/cards/new'),
        ),
        page.getByRole('button', { name: 'Create card' }).click(),
    ]);
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
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

async function addColumnFromSettings(
    page: Page,
    projectId: string,
    label: string,
): Promise<void> {
    await page.goto(`/projects/${projectId}/settings/columns`);
    const settings = page.locator('[data-board-column-settings]');
    await settings
        .getByRole('button', { name: 'Add a column', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox').fill(label);
    await dialog
        .getByRole('button', { name: 'Add column', exact: true })
        .click();
    await expect(
        settings.getByRole('heading', { name: label, exact: true }),
    ).toBeVisible(ROUND_TRIP);
}

async function openMenu(page: Page, slug: string): Promise<void> {
    await page
        .locator(
            `${COLUMN}[data-column-slug="${slug}"] .lp-board__column-menu-trigger`,
        )
        .click();
}

interface Board {
    projectId: string;
    boardUrl: string;
}

const test = base.extend<{ board: Board }>({
    board: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);
            await setBoardFlag(page.request, true);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            await registerAndLogin(
                page,
                `e2e+columns+${tag}+${RUN}@example.com`,
            );

            const projectId = await seedProject(page);
            await createCard(page, projectId, 'Waiting', 'Next');

            const boardUrl = `/projects/${projectId}/board`;
            await page.goto(boardUrl);
            await expect(page.locator(COLUMN)).toHaveCount(4);

            await use({ projectId, boardUrl });
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

test.afterAll(async ({ request }) => {
    await setBoardFlag(request, false);
});

test('board filters retain an outline in forced colors', async ({ page }) => {
    await page.emulateMedia({ forcedColors: 'active' });
    const field = page.locator('.lp-board-toolbar__input');
    await field.focus();
    await expect(field).toBeFocused();
    await expect(field).toHaveCSS('outline-style', 'solid');
    await expect(field).toHaveCSS('outline-width', '2px');
    await expect(field).toHaveCSS('outline-offset', '0px');
    await expect(field).not.toHaveCSS('outline-color', 'rgba(0, 0, 0, 0)');
});

test('board controls remain usable at enlarged text sizes without page overflow', async ({
    page,
}, testInfo) => {
    for (const fontSize of ['100%', '200%']) {
        await page.evaluate((size) => {
            document.documentElement.style.fontSize = size;
        }, fontSize);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await page.screenshot({
                path: testInfo.outputPath(`board-${width}-${fontSize}.png`),
                animations: 'disabled',
            });
            await expect
                .poll(() =>
                    page.evaluate(
                        () =>
                            document.documentElement.scrollWidth -
                            window.innerWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            for (const link of await page
                .locator('.lp-board-head__actions a')
                .all()) {
                await expect(link).toBeInViewport({ ratio: 1 });
                expect(
                    await link.evaluate((element) => {
                        const bounds = element.getBoundingClientRect();
                        const range = document.createRange();
                        range.selectNodeContents(element);
                        const content = range.getBoundingClientRect();
                        return Math.max(
                            bounds.left - content.left,
                            content.right - bounds.right,
                        );
                    }),
                ).toBeLessThanOrEqual(1);
            }
            const query = page.getByRole('searchbox', {
                name: 'Search cards',
                exact: true,
            });
            await query.focus();
            await expect(query).toBeInViewport({ ratio: 1 });
            const filters = page.locator('.lp-board-toolbar__filters');
            await expectFilterFocusRingVisible(query, filters);
        }
    }
    const query = page.getByRole('searchbox', {
        name: 'Search cards',
        exact: true,
    });
    await query.fill('No matching card');
    await expect(
        page.locator('[data-board-filter-target="empty"]'),
    ).toBeVisible();
    await query.fill('Waiting');
    await expect(
        page.locator('[data-board-filter-target="empty"]'),
    ).toBeHidden();
    await page.getByRole('button', { name: 'List', exact: true }).click();
    await expect(page.locator('.lp-board-list')).toBeVisible();
    await expect(page.locator('.lp-board-list__row:not([hidden])')).toHaveCount(
        1,
    );
    await page.getByRole('button', { name: 'Board', exact: true }).click();
    await expect(page.locator('.lp-board__columns')).toBeVisible();
    await expect
        .poll(() =>
            page.evaluate(
                () => document.documentElement.scrollWidth - window.innerWidth,
            ),
        )
        .toBeLessThanOrEqual(1);
});

test('column settings fits long names and enlarged text', async ({
    page,
    board,
}, testInfo) => {
    const label = 'A'.repeat(100);
    await addColumnFromSettings(page, board.projectId, label);
    const settings = page.locator('[data-board-column-settings]');
    for (const fontSize of ['100%', '200%']) {
        await page.evaluate((size) => {
            document.documentElement.style.fontSize = size;
        }, fontSize);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await expect
                .poll(() =>
                    settings.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            await expect
                .poll(() =>
                    page.evaluate(
                        () =>
                            document.documentElement.scrollWidth -
                            window.innerWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            await page.screenshot({
                path: testInfo.outputPath(`columns-${width}-${fontSize}.png`),
                fullPage: true,
                animations: 'disabled',
            });
            const addColumn = settings.getByRole('button', {
                name: 'Add a column',
                exact: true,
            });
            await addColumn.scrollIntoViewIfNeeded();
            await expect(addColumn).toBeInViewport({ ratio: 1 });
            await expect
                .poll(() =>
                    addColumn.locator('svg').evaluate((icon) => {
                        const bounds = icon.getBoundingClientRect();
                        return Math.abs(bounds.width - bounds.height);
                    }),
                )
                .toBeLessThanOrEqual(1);
            await page.screenshot({
                path: testInfo.outputPath(
                    `columns-actions-${width}-${fontSize}.png`,
                ),
                animations: 'disabled',
            });
        }
    }
});

test('column settings preserves edits and navigation', async ({
    page,
    board,
}) => {
    await page
        .getByRole('link', { name: 'Board settings', exact: true })
        .click();
    const settingsUrl = `/projects/${board.projectId}/settings/columns`;
    await expect(page).toHaveURL(settingsUrl);
    const settings = page.locator('[data-board-column-settings]');
    await expect(settings).toBeVisible();
    await page
        .getByRole('button', { name: 'Add a column', exact: true })
        .click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('textbox').fill('Parked');
    await dialog
        .getByRole('button', { name: 'Add column', exact: true })
        .click();
    await expect(
        settings.getByRole('heading', { name: 'Parked', exact: true }),
    ).toBeVisible(ROUND_TRIP);
    await expect(page).toHaveURL(settingsUrl);

    const parked = settings.locator('[data-column-id]').filter({
        has: page.getByRole('heading', { name: 'Parked', exact: true }),
    });
    await parked.getByRole('button', { name: 'Move up', exact: true }).click();
    await expect(settings.locator('.lp-settings-column__name code')).toHaveText(
        ['backlog', 'next', 'in-progress', 'parked', 'done'],
        ROUND_TRIP,
    );
    await expect(page).toHaveURL(settingsUrl);
    await page.reload();
    await expect(settings.locator('.lp-settings-column__name code')).toHaveText(
        ['backlog', 'next', 'in-progress', 'parked', 'done'],
    );
    await expect(
        settings
            .locator('[data-column-id]')
            .first()
            .getByRole('button', { name: 'Move up', exact: true }),
    ).toHaveCount(0);
    await expect(
        settings
            .locator('[data-column-id]')
            .last()
            .getByRole('button', { name: 'Move down', exact: true }),
    ).toHaveCount(0);

    await parked
        .getByRole('button', { name: 'Configure Parked', exact: true })
        .click();
    const configureParked = page.getByRole('dialog', {
        name: 'Configure Parked',
        exact: true,
    });
    await configureParked
        .getByLabel('Column name', { exact: true })
        .fill('Done');
    await expect(
        configureParked.locator('.lp-board__slug-refusal'),
    ).toContainText('already has this slug');
    await configureParked
        .getByRole('button', { name: 'Save column', exact: true })
        .click();
    await expect(
        configureParked.locator('[data-field-errors="label"]'),
    ).toContainText('already has this slug', ROUND_TRIP);
    await expect(
        configureParked.getByLabel('Column name', { exact: true }),
    ).toHaveValue('Done');
    await expect(settings).toBeVisible();
    await configureParked
        .getByLabel('Column name', { exact: true })
        .fill('On hold');
    await configureParked
        .getByRole('button', { name: 'Save column', exact: true })
        .click();
    await expect(
        settings.getByRole('heading', { name: 'On hold', exact: true }),
    ).toBeVisible(ROUND_TRIP);
    await expect(page).toHaveURL(settingsUrl);
    await page.reload();
    await expect(settings.locator('.lp-settings-column__name code')).toHaveText(
        ['backlog', 'next', 'in-progress', 'on-hold', 'done'],
    );

    const renamed = settings.locator('[data-column-id]').filter({
        has: page.getByRole('heading', { name: 'On hold', exact: true }),
    });
    const configureRenamed = page.getByRole('dialog', {
        name: 'Configure On hold',
        exact: true,
    });
    await renamed
        .getByRole('button', { name: 'Configure On hold', exact: true })
        .click();
    await configureRenamed
        .getByRole('checkbox', {
            name: 'A finishing point for completed work',
            exact: true,
        })
        .check();
    await configureRenamed
        .getByRole('button', { name: 'Save column', exact: true })
        .click();
    await expect(renamed.locator('.lp-board__column-flag')).toHaveText(
        'Terminal',
        ROUND_TRIP,
    );
    await expect(page).toHaveURL(settingsUrl);

    // Leaving the terminal flag and taking the default is one save.
    await renamed
        .getByRole('button', { name: 'Configure On hold', exact: true })
        .click();
    await configureRenamed
        .getByRole('checkbox', {
            name: 'A finishing point for completed work',
            exact: true,
        })
        .uncheck();
    await configureRenamed
        .getByRole('checkbox', { name: 'Default for new cards', exact: true })
        .check();
    await configureRenamed
        .getByRole('button', { name: 'Save column', exact: true })
        .click();
    await expect(renamed.locator('.lp-board__column-flag')).toHaveText(
        'Default',
        ROUND_TRIP,
    );
    await expect(page).toHaveURL(settingsUrl);
    await page.reload();
    await expect(renamed.locator('.lp-board__column-flag')).toHaveText(
        'Default',
    );
});

test('document dialogs create, retain and clear card links', async ({
    page,
    board,
}) => {
    await page.goto(`/projects/${board.projectId}/documents`);
    await page
        .getByRole('button', { name: 'New document', exact: true })
        .click();
    const createDialog = page.getByRole('dialog', {
        name: 'New document',
        exact: true,
    });
    await createDialog
        .getByLabel('Title', { exact: true })
        .fill('Linked document');
    await createDialog
        .getByLabel('Markdown', { exact: true })
        .fill('# Linked content');
    await createDialog
        .getByRole('checkbox', { name: '#1 Waiting', exact: true })
        .check();
    await createDialog
        .getByRole('button', { name: 'Create document', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Linked document', exact: true }),
    ).toBeVisible(ROUND_TRIP);
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    const reviseDialog = page.getByRole('dialog', { name: 'Revise document' });
    await expect(
        reviseDialog.getByRole('checkbox', { name: '#1 Waiting', exact: true }),
    ).toBeChecked();
    await reviseDialog
        .getByLabel('Revision note', { exact: true })
        .fill('Keep the card link.');
    await reviseDialog
        .getByRole('button', { name: 'Save new version' })
        .click();
    await expect(page.locator('.lp-review-doc__version')).toHaveText(
        'v2',
        ROUND_TRIP,
    );
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    await expect(
        reviseDialog.getByRole('checkbox', { name: '#1 Waiting', exact: true }),
    ).toBeChecked();
    await reviseDialog
        .getByRole('checkbox', { name: '#1 Waiting', exact: true })
        .uncheck();
    await reviseDialog
        .getByLabel('Revision note', { exact: true })
        .fill('Remove the card link.');
    await reviseDialog
        .getByRole('button', { name: 'Save new version' })
        .click();
    await expect(page.locator('.lp-review-doc__version')).toHaveText(
        'v3',
        ROUND_TRIP,
    );
    await page.goto(`/projects/${board.projectId}/documents`);
    const row = page
        .locator('[data-document-id]')
        .filter({ hasText: 'Linked document' });
    await expect(row.locator('.lp-document-row__linked-card')).toHaveText('—');
});

test('a card opens in a stable drawer and returns focus when closed', async ({
    page,
}) => {
    const cardLink = page.getByRole('link', { name: /Waiting/ });
    await cardLink.focus();
    await cardLink.click();

    const drawer = page.getByRole('dialog', { name: 'Card details' });
    await expect(drawer).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Board' })).toBeVisible();
    await expect(drawer.getByRole('tab', { name: 'Overview' })).toHaveAttribute(
        'aria-selected',
        'true',
    );

    await drawer.getByRole('tab', { name: 'Conversation' }).click();
    await expect(drawer).toBeVisible();
    await expect(
        drawer.getByRole('tab', { name: 'Conversation' }),
    ).toHaveAttribute('aria-selected', 'true');

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(cardLink).toBeFocused();

    await cardLink.click();
    await expect(drawer.getByRole('tab', { name: 'Overview' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await expect(page).not.toHaveURL(/tab=conversation/);

    const cardPath = await cardLink.getAttribute('href');
    await page.goto(`${cardPath}?tab=feedback`);
    await expect(page.getByRole('tab', { name: 'Feedback' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    await page.getByRole('tab', { name: 'Conversation' }).click();
    await expect(page).toHaveURL(`${cardPath}?tab=conversation`);
    await page.reload();
    await expect(
        page.getByRole('tab', { name: 'Conversation' }),
    ).toHaveAttribute('aria-selected', 'true');
});

test('an owner adds a column after the last one', async ({ page, board }) => {
    await addColumnFromSettings(page, board.projectId, 'Parked');

    await page.goto(board.boardUrl);
    expect(await slugs(page)).toEqual([
        'backlog',
        'next',
        'in-progress',
        'done',
        'parked',
    ]);
});

test('a rename shows the new slug before it saves', async ({ page, board }) => {
    await openMenu(page, 'next');
    await page
        .locator(`${COLUMN}[data-column-slug="next"]`)
        .getByRole('button', { name: 'Rename' })
        .click();

    const dialog = page.locator('dialog[open]');
    await dialog.getByLabel('Name').fill('Up next!');
    await expect(dialog.locator('.lp-board__slug-value')).toHaveText('up-next');

    await dialog.getByRole('button', { name: 'Save name' }).click();

    await expect
        .poll(() => slugs(page), ROUND_TRIP)
        .toEqual(['backlog', 'up-next', 'in-progress', 'done']);
    await page.goto(board.boardUrl);
    await expect(
        page.locator(`${COLUMN}[data-column-slug="up-next"] h2`),
    ).toHaveText('Up next!');
});

test('a header dragged past its neighbour reorders the columns', async ({
    page,
    board,
}) => {
    const columnsDoNotOverlap = await page
        .locator(COLUMN)
        .evaluateAll((columns) => {
            const rectangles = columns.map((column) =>
                column.getBoundingClientRect(),
            );

            return rectangles.every(
                (rectangle, index) =>
                    index === 0 ||
                    rectangles[index - 1].right <= rectangle.left,
            );
        });
    expect(columnsDoNotOverlap).toBe(true);
    const grip = page.locator(
        `${COLUMN}[data-column-slug="backlog"] .lp-board__column-grip`,
    );
    const target = page.locator(`${COLUMN}[data-column-slug="in-progress"]`);
    const width = await target.evaluate(
        (element) => element.getBoundingClientRect().width,
    );

    // Past the middle of In progress, so Backlog lands after it.
    const written = page.waitForResponse((response) =>
        response.url().endsWith('/board/columns/reorder'),
    );
    await grip.dragTo(target, {
        targetPosition: { x: width - 8, y: 24 },
    });
    await written;

    await page.goto(board.boardUrl);
    expect(await slugs(page)).toEqual([
        'next',
        'in-progress',
        'backlog',
        'done',
    ]);
});

test('an empty column asks for confirmation before it is deleted', async ({
    page,
    board,
}) => {
    await openMenu(page, 'in-progress');
    await page
        .locator(`${COLUMN}[data-column-slug="in-progress"]`)
        .getByRole('button', { name: 'Delete column' })
        .click();

    const dialog = page.locator('dialog[open]');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('holds no cards');
    await expect(dialog.getByLabel('Move the cards to')).toHaveCount(0);

    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toHaveCount(0);
    await page.goto(board.boardUrl);
    expect(await slugs(page)).toEqual([
        'backlog',
        'next',
        'in-progress',
        'done',
    ]);

    await openMenu(page, 'in-progress');
    await page
        .locator(`${COLUMN}[data-column-slug="in-progress"]`)
        .getByRole('button', { name: 'Delete column' })
        .click();
    await page
        .locator('dialog[open]')
        .getByRole('button', { name: 'Delete the column' })
        .click();

    await expect(page.getByText('Deleted the column')).toBeVisible(ROUND_TRIP);
    await page.goto(board.boardUrl);
    expect(await slugs(page)).toEqual(['backlog', 'next', 'done']);
});

test('a column with cards is deleted into the target the dialog picks', async ({
    page,
    board,
}) => {
    await openMenu(page, 'next');
    await page
        .locator(`${COLUMN}[data-column-slug="next"]`)
        .getByRole('button', { name: 'Delete column' })
        .click();

    const dialog = page.locator('dialog[open]');
    await expect(dialog).toContainText('holds 1 card');
    await expect(dialog.getByLabel('Move the cards to')).toBeVisible();
    expect(await slugs(page)).toEqual([
        'backlog',
        'next',
        'in-progress',
        'done',
    ]);
    await dialog
        .getByLabel('Move the cards to')
        .selectOption({ label: 'In progress' });
    await dialog
        .getByRole('button', { name: 'Move the cards and delete' })
        .click();

    await expect(page.getByText('moved its card')).toBeVisible(ROUND_TRIP);
    await page.goto(board.boardUrl);
    expect(await slugs(page)).toEqual(['backlog', 'in-progress', 'done']);
    await expect(
        page.locator(
            `${COLUMN}[data-column-slug="in-progress"] [data-card-title="Waiting"]`,
        ),
    ).toBeVisible();
});
