/**
 * Browser coverage for the Backlog page: the filters, the column headers that
 * sort, the Move to menu of a row, the bulk bar and the state where the
 * filters match nothing.
 *
 * The cards come from the MCP card_create tool, because it sets the type and
 * the parent in one call. A card with no status lands in Backlog.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { accessToken, suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBacklogPage1!';

// Under a loaded run, the POST and the stream take longer than the 5 s default.
const ROUND_TRIP = { timeout: process.env.COVERAGE ? 20_000 : 15_000 };

async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/(welcome|projects\/.+)$/, {
        timeout: 15000,
    });
}

interface Card {
    id: string;
    number: number;
}

type CreateCard = (
    title: string,
    options?: { type?: string; status?: string; parent?: Card },
) => Promise<Card>;

/** Opens an MCP session with a token bound to the project, and returns a card_create call. */
async function mcpCards(page: Page, projectId: string): Promise<CreateCard> {
    const token = await accessToken(page, 'mcp', projectId);
    const headers: Record<string, string> = {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
    };

    const initialize = await page.request.post('/mcp', {
        headers,
        data: {
            jsonrpc: '2.0',
            id: 1,
            method: 'initialize',
            params: {
                protocolVersion: '2024-11-05',
                capabilities: {},
                clientInfo: { name: 'e2e', version: '1' },
            },
        },
    });
    expect(initialize.ok()).toBeTruthy();
    headers['Mcp-Session-Id'] = initialize.headers()['mcp-session-id'] ?? '';
    expect(headers['Mcp-Session-Id']).not.toBe('');
    await page.request.post('/mcp', {
        headers,
        data: { jsonrpc: '2.0', method: 'notifications/initialized' },
    });

    let id = 2;

    return async (title, options = {}) => {
        const response = await page.request.post('/mcp', {
            headers,
            data: {
                jsonrpc: '2.0',
                id: id++,
                method: 'tools/call',
                params: {
                    name: 'card_create',
                    arguments: {
                        title,
                        body: '',
                        type: options.type ?? 'feature',
                        ...(options.status ? { status: options.status } : {}),
                        ...(options.parent
                            ? { parentCardId: options.parent.id }
                            : {}),
                    },
                },
            },
        });
        const answer = await response.json();
        expect(answer.result?.isError, JSON.stringify(answer)).toBeFalsy();
        const card = answer.result.structuredContent;

        return { id: card.cardId, number: card.number };
    };
}

interface Backlog {
    projectId: string;
    backlogUrl: string;
    create: CreateCard;
}

const test = base.extend<{ backlog: Backlog }>({
    backlog: async ({ page }, use, testInfo) => {
        await suppressToolbar(page);
        await suppressWidget(page);

        const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
        const email = `e2e+backlog-page+${tag}+${RUN}@example.com`;
        const registered = await page.request.post('/dev/register-and-verify', {
            form: { fullName: 'E2E Backlog User', email, password: PASSWORD },
        });
        expect(registered.status()).toBe(200);
        await login(page, email);

        const seeded = await page.request.post('/dev/seed/document', {
            form: { title: 'E2E Backlog Project', markdown: '# Backlog' },
        });
        expect(seeded.status()).toBe(201);
        const projectId = (await seeded.json()).projectId as string;

        await use({
            projectId,
            backlogUrl: `/projects/${projectId}/board/backlog`,
            create: await mcpCards(page, projectId),
        });
    },
});

// Board pages run near the default budget beside three other workers.
test.slow();

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

function row(page: Page, card: Card) {
    return page.locator(`[data-backlog-card-id="${card.id}"]`);
}

function moveResponse(page: Page, path: string) {
    return page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname.endsWith(path),
    );
}

test('a filtered row moves to Next and leaves the list', async ({
    page,
    backlog,
}) => {
    const epic = await backlog.create('Make the board fast', {
        type: 'epic',
        status: 'next',
    });
    const target = await backlog.create('Drag loses rank', {
        type: 'bug',
        parent: epic,
    });
    const otherBug = await backlog.create('Loose bug', { type: 'bug' });
    await backlog.create('Epic feature', { parent: epic });

    await page.goto(backlog.backlogUrl);
    await expect(page.locator('#backlog-filter-count')).toHaveText('3 cards');

    await page.getByLabel('Type', { exact: true }).selectOption('bug');
    await expect(page).toHaveURL(/type=bug/, ROUND_TRIP);
    await page
        .getByLabel('Epic', { exact: true })
        .selectOption({ label: `#${epic.number} Make the board fast` });
    await expect(page).toHaveURL(/epic=/, ROUND_TRIP);

    await expect(row(page, target)).toBeVisible();
    await expect(row(page, otherBug)).toHaveCount(0);
    await expect(page.locator('#backlog-filter-count')).toHaveText(
        '1 of 3 cards',
    );

    await row(page, target)
        .getByRole('button', {
            name: `Move card #${target.number} to a column`,
        })
        .click();
    const moved = moveResponse(page, '/move');
    await row(page, target).getByRole('button', { name: 'Next' }).click();
    expect((await moved).status()).toBe(200);

    await expect(page.locator('#backlog-confirmation')).toHaveText(
        '1 card moved to Next.',
        ROUND_TRIP,
    );
    await expect(row(page, target)).toHaveCount(0);
    await expect(page.getByText('No card matches')).toBeVisible();
    await expect(
        page.locator('[data-backlog-live-target="notice"]'),
    ).toBeHidden();

    await page.goto(`/projects/${backlog.projectId}/board`);
    await expect(
        page.locator(
            `[data-board-drag-target="card"][data-card-id="${target.id}"]`,
        ),
    ).toBeVisible(ROUND_TRIP);
});

test('ticked rows move to Next together', async ({ page, backlog }) => {
    const first = await backlog.create('First waiting');
    const second = await backlog.create('Second waiting');
    const third = await backlog.create('Third waiting');
    const stays = await backlog.create('Stays waiting');

    await page.goto(backlog.backlogUrl);
    const bar = page.locator('#backlog-bulk-form');
    await expect(bar).toBeHidden();

    for (const card of [first, second, third]) {
        await page
            .getByRole('checkbox', { name: `Select card #${card.number}` })
            .check();
    }
    await expect(bar).toBeVisible();
    await expect(bar.locator('.lp-backlog-bulk__count')).toHaveText('3');

    const moved = moveResponse(page, '/bulk-move');
    await bar.getByRole('button', { name: 'Move to Next' }).click();
    expect((await moved).status()).toBe(200);

    await expect(page.locator('#backlog-confirmation')).toHaveText(
        '3 cards moved to Next.',
        ROUND_TRIP,
    );
    for (const card of [first, second, third]) {
        await expect(row(page, card)).toHaveCount(0);
    }
    await expect(row(page, stays)).toBeVisible();
    await expect(bar).toBeHidden();
    await expect(page.locator('#backlog-filter-count')).toHaveText('1 card');
});

test('the column headers sort the list, newest first by default', async ({
    page,
    backlog,
}) => {
    const feature = await backlog.create('Sortable feature');
    const bug = await backlog.create('Sortable bug', { type: 'bug' });
    const docs = await backlog.create('Sortable docs', { type: 'docs' });

    const rows = page.locator('[data-backlog-card-id]');
    const header = (name: string) =>
        page.getByRole('columnheader').filter({
            has: page.getByRole('link', { name, exact: true }),
        });

    await page.goto(backlog.backlogUrl);
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(0)).toHaveAttribute('data-backlog-card-id', docs.id);
    await expect(rows.nth(1)).toHaveAttribute('data-backlog-card-id', bug.id);
    await expect(rows.nth(2)).toHaveAttribute(
        'data-backlog-card-id',
        feature.id,
    );
    await expect(header('Added')).toHaveAttribute('aria-sort', 'descending');
    await expect(header('Type')).toHaveAttribute('aria-sort', 'none');

    await page.getByRole('link', { name: 'Type', exact: true }).click();
    await expect(page).toHaveURL(/sort=type&dir=asc/, ROUND_TRIP);
    await expect(header('Type')).toHaveAttribute('aria-sort', 'ascending');
    await expect(header('Added')).toHaveAttribute('aria-sort', 'none');
    await expect(rows.nth(0)).toHaveAttribute('data-backlog-card-id', bug.id);
    await expect(rows.nth(1)).toHaveAttribute('data-backlog-card-id', docs.id);
    await expect(rows.nth(2)).toHaveAttribute(
        'data-backlog-card-id',
        feature.id,
    );

    await page.getByRole('link', { name: 'Type', exact: true }).click();
    await expect(page).toHaveURL(/sort=type&dir=desc/, ROUND_TRIP);
    await expect(header('Type')).toHaveAttribute('aria-sort', 'descending');
    await expect(rows.nth(0)).toHaveAttribute(
        'data-backlog-card-id',
        feature.id,
    );
});

test('filters that match nothing offer to clear them', async ({
    page,
    backlog,
}) => {
    const card = await backlog.create('Paginate the terminal columns');

    await page.goto(`${backlog.backlogUrl}?search=otterwhale`);
    await expect(page.getByText('No card matches')).toBeVisible();
    await expect(page.locator('#backlog-filter-count')).toHaveText(
        '0 of 1 card',
    );

    await page.getByRole('link', { name: 'Clear filters' }).click();
    await expect(page).toHaveURL(new RegExp(`${backlog.backlogUrl}$`));
    await expect(row(page, card)).toBeVisible();
});
