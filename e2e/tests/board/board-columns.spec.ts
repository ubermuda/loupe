/**
 * Browser coverage for editing a board's columns inline: add a column, rename
 * one with its slug preview, drag a header to reorder, and delete a column
 * whose cards move to a target. Each change is read back after a reload,
 * because the server is what decides.
 */

import {
    test as base,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBoardColumns1!';

const COLUMN = '[data-board-columns-target="column"]';

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
    await expect(page).toHaveURL('/welcome');
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
    await page.getByRole('button', { name: 'Create card' }).click();
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

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setBoardFlag(request, false);
});

test('an owner adds a column after the last one', async ({ page, board }) => {
    await page.getByLabel('New column').fill('Parked');
    await page.getByRole('button', { name: 'Add column' }).click();

    await expect
        .poll(() => slugs(page))
        .toEqual(['backlog', 'next', 'in-progress', 'done', 'parked']);

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
        .poll(() => slugs(page))
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

    await expect(page.getByText('Deleted the column')).toBeVisible();
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

    await expect(page.getByText('moved its card')).toBeVisible();
    await page.goto(board.boardUrl);
    expect(await slugs(page)).toEqual(['backlog', 'in-progress', 'done']);
    await expect(
        page.locator(
            `${COLUMN}[data-column-slug="in-progress"] [data-card-title="Waiting"]`,
        ),
    ).toBeVisible();
});
