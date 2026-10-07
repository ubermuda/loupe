/**
 * Browser coverage for epic lanes on the board: the lane toggle, the collapse
 * that one browser keeps, and a drag that changes the parent of a card.
 *
 * The cards come from the MCP card_create tool, because it sets the type, the
 * column and the parent in one call. The card form would need an autocomplete
 * for each parent.
 */

import {
    test as base,
    expect,
    type Browser,
    type Page,
} from '@playwright/test';
import {
    accessToken,
    signedInPage,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eEpicLanes1!';

const CARD = '[data-board-drag-target="card"]';
const READY = '#board[data-board-drag-ready="true"]';
// Stamped on the board on screen, so a test can prove the board changed in
// place and was not replaced.
const MARK = 'data-e2e-board-generation';

async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // A first sign-in lands on the welcome page, and a later one on the project.
    await expect(page).toHaveURL(/\/(welcome|projects\/.+)$/, {
        timeout: 15000,
    });
}

interface Card {
    id: string;
    number: number;
}

/** A card with no status lands in Backlog, which the board does not draw, so the helper puts it in Next. */
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
                        status: options.status ?? 'next',
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

async function markBoard(page: Page): Promise<void> {
    await page
        .locator('#board')
        .evaluate((board, mark) => board.setAttribute(mark, '1'), MARK);
}

/** The lane toggle changes the board through the hub, which keeps no history, so the page waits for it. */
async function openLive(page: Page, url: string): Promise<void> {
    await page.goto(url);
    await expect(page.locator(READY)).toBeAttached();
    await expect(page.locator('[data-board-live-connected]')).toHaveCount(1, {
        timeout: 15000,
    });
}

/** Resolves when the live update has taken every lane off the board, which it changes in place. */
async function lanesGone(page: Page): Promise<void> {
    await expect(page.locator('.lp-board-lane')).toHaveCount(0, {
        timeout: 15000,
    });
    await expect(page.locator(`${READY}[${MARK}]`)).toBeAttached();
}

/** Counts the board:placed and board:place-missed events of one card until the next page load. */
async function watchPlacements(page: Page, card: Card): Promise<void> {
    await page.evaluate((cardId) => {
        const counts = { placed: 0, missed: 0 };
        (window as unknown as { e2ePlacements: typeof counts }).e2ePlacements =
            counts;
        const count = (outcome: keyof typeof counts) => (event: Event) => {
            if ((event as CustomEvent).detail?.cardId === cardId) {
                counts[outcome] += 1;
            }
        };
        document.addEventListener('board:placed', count('placed'));
        document.addEventListener('board:place-missed', count('missed'));
    }, card.id);
}

async function placements(
    page: Page,
): Promise<{ placed: number; missed: number }> {
    return page.evaluate(
        () =>
            (
                window as unknown as {
                    e2ePlacements: { placed: number; missed: number };
                }
            ).e2ePlacements,
    );
}

/** Resolves when the answer to a move has placed the card in place, with no board reload. */
async function cardPlaced(
    page: Page,
    card: ReturnType<Page['locator']>,
): Promise<void> {
    await expect(card).not.toHaveAttribute('aria-busy', 'true');
    await expect
        .poll(async () => (await placements(page)).placed)
        .toBeGreaterThan(0);
    expect((await placements(page)).missed).toBe(0);
    await expect(page.locator(`${READY}[${MARK}]`)).toBeAttached();
    await expect(card).not.toHaveAttribute('data-board-stale');
}

function lane(page: Page, key: string) {
    return page.locator(`.lp-board-lane[data-lane="${key}"]`);
}

async function columnId(page: Page, slug: string): Promise<string> {
    const id = await page
        .locator(
            `.lp-board-lane[data-lane="other"] .lp-board-lane__column[data-column-slug="${slug}"] [data-board-drag-target="group"]`,
        )
        .getAttribute('data-column');
    expect(id).not.toBeNull();

    return id ?? '';
}

function cell(page: Page, laneKey: string, column: string) {
    return page.locator(
        `[data-board-drag-target="group"][data-lane="${laneKey}"][data-column="${column}"]`,
    );
}

/** Drags a card by its title to the middle of a cell, with pointer events. */
async function dragCardToCell(
    page: Page,
    card: Card,
    target: ReturnType<typeof cell>,
): Promise<void> {
    const title = page.locator(
        `${CARD}[data-card-id="${card.id}"] .lp-board-card__title`,
    );
    const from = await title.boundingBox();
    const to = await target.boundingBox();
    expect(from).not.toBeNull();
    expect(to).not.toBeNull();
    if (from === null || to === null) {
        return;
    }
    const x = to.x + to.width / 2;
    const y = to.y + to.height / 2;

    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(x, y, { steps: 20 });
    await page.mouse.move(x, y + 1, { steps: 4 });
    await page.mouse.up();
}

interface Board {
    email: string;
    projectId: string;
    boardUrl: string;
    create: CreateCard;
}

const test = base.extend<{ board: Board; livePage: Page }>({
    board: async ({ page }, use, testInfo) => {
        await suppressToolbar(page);
        await suppressWidget(page);

        const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
        const email = `e2e+epic-lanes+${tag}+${RUN}@example.com`;
        const registered = await page.request.post('/dev/register-and-verify', {
            form: { fullName: 'E2E Epic User', email, password: PASSWORD },
        });
        expect(registered.status()).toBe(200);
        await login(page, email);

        const seeded = await page.request.post('/dev/seed/document', {
            form: { title: 'E2E Epic Project', markdown: '# Epics' },
        });
        expect(seeded.status()).toBe(201);
        const projectId = (await seeded.json()).projectId as string;

        await use({
            email,
            projectId,
            boardUrl: `/projects/${projectId}/board`,
            create: await mcpCards(page, projectId),
        });
    },
    // The default page sends the test headers to the hub too, and the hub refuses the preflight they cause.
    livePage: async ({ browser, board }, use) => {
        const page = await signedInPage(browser, board.email, PASSWORD);
        await use(page);
        await page.context().close();
    },
});

// Board pages run near the default budget beside three other workers.
test.slow();

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

test('each lane cell scrolls its own cards and the page stays still', async ({
    page,
    board,
}) => {
    const epic = await board.create(`Tall epic ${RUN}`, { type: 'epic' });
    for (let index = 1; index <= 8; index++) {
        await board.create(`Tall child ${index} ${RUN}`, { parent: epic });
        await board.create(`Loose card ${index} ${RUN}`);
    }

    await page.goto(board.boardUrl);
    await expect(page.locator(READY)).toBeAttached();
    const next = await columnId(page, 'next');
    const scrolls = (laneKey: string) =>
        cell(page, laneKey, next).evaluate((element) => ({
            overflow: getComputedStyle(element).overflowY,
            hidden: element.scrollHeight - element.clientHeight,
        }));

    // An epic lane stops at its height cap, and "Other cards" fills the rest.
    const cells = await lane(page, epic.id)
        .locator('.lp-board-lane__cells')
        .boundingBox();
    expect(cells?.height).toBeLessThanOrEqual(320);
    for (const laneKey of [epic.id, 'other']) {
        const cellScroll = await scrolls(laneKey);
        expect(cellScroll.overflow).toBe('auto');
        expect(cellScroll.hidden).toBeGreaterThan(0);
    }
    await expect
        .poll(() =>
            page.evaluate(
                () =>
                    document.documentElement.scrollHeight - window.innerHeight,
            ),
        )
        .toBeLessThanOrEqual(1);

    await cell(page, 'other', next).evaluate((element) =>
        element.scrollTo(0, element.scrollHeight),
    );
    await expect(page.getByRole('heading', { level: 1 })).toBeInViewport();
});

test('an epic in the Backlog keeps its lane on the board', async ({
    page,
    board,
}) => {
    const epic = await board.create(`Waiting epic ${RUN}`, {
        type: 'epic',
        status: 'backlog',
    });
    const child = await board.create(`Child of the waiting epic ${RUN}`, {
        parent: epic,
    });
    await board.create(`Waiting child ${RUN}`, {
        parent: epic,
        status: 'backlog',
    });

    await page.goto(board.boardUrl);
    await expect(page.locator(READY)).toBeAttached();
    const next = await columnId(page, 'next');

    await expect(lane(page, epic.id)).toBeVisible();
    await expect(
        cell(page, epic.id, next).locator(
            `${CARD}[data-card-id="${child.id}"]`,
        ),
    ).toHaveCount(1);
    await expect(
        lane(page, epic.id).locator('[data-lane-progress]'),
    ).toHaveText('0/2 done');
    await expect(page.locator('.lp-board-backlog__count')).toHaveText('2');
    await page.getByRole('button', { name: 'List', exact: true }).click();
    await expect(page.locator('.lp-board-list')).toBeVisible();
    await expect(page.locator(`#board-row-${child.id}`)).toHaveCount(1);
    await expect(page.locator(`#board-row-${epic.id}`)).toHaveCount(0);
});

test('a lane switched off shows the parent tag and the progress', async ({
    livePage: page,
    board,
}) => {
    const epic = await board.create(`Epic ${RUN}`, { type: 'epic' });
    const open = await board.create(`Open child ${RUN}`, { parent: epic });
    await board.create(`Done child ${RUN}`, { parent: epic, status: 'done' });

    await openLive(page, board.boardUrl);
    const epicLane = lane(page, epic.id);
    await expect(epicLane.locator('[data-lane-progress]')).toHaveText(
        '1/2 done',
    );

    await markBoard(page);
    await epicLane
        .getByRole('button', { name: 'Hide the lane on the board' })
        .click();
    await lanesGone(page);

    await expect(
        page.locator(
            `${CARD}[data-card-id="${open.id}"] [data-card-parent-tag]`,
        ),
    ).toHaveText(`#${epic.number}`);
    await expect(
        page.locator(`${CARD}[data-card-id="${epic.id}"] [data-card-progress]`),
    ).toHaveText('1/2 done');
});

test('the lane button in the card drawer shows the lane on the board behind it', async ({
    livePage: page,
    board,
}) => {
    const epic = await board.create(`Drawer epic ${RUN}`, { type: 'epic' });
    await board.create(`Drawer child ${RUN}`, { parent: epic });

    await openLive(page, board.boardUrl);
    await markBoard(page);
    await lane(page, epic.id)
        .getByRole('button', { name: 'Hide the lane on the board' })
        .click();
    await lanesGone(page);

    await page
        .locator(`${CARD}[data-card-id="${epic.id}"] .lp-board-card__title`)
        .click();
    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    await expect(drawer).toHaveJSProperty('open', true);
    await markBoard(page);
    await drawer
        .getByRole('button', { name: 'Show as a lane on the board' })
        .click();

    await expect(lane(page, epic.id)).toBeVisible();
    await expect(
        page.locator(`${CARD}[data-card-id="${epic.id}"]`),
    ).toHaveCount(0);
    // The lane shows in place, so the board keeps its mark.
    await expect(page.locator(`${READY}[${MARK}]`)).toBeAttached();
    await expect(drawer).toHaveJSProperty('open', true);
    await expect(
        drawer.getByRole('button', { name: 'Hide the lane on the board' }),
    ).toBeVisible();
});

test('a collapsed lane stays collapsed in this browser only', async ({
    page,
    board,
    browser,
}) => {
    const first = await board.create(`First epic ${RUN}`, { type: 'epic' });
    const second = await board.create(`Second epic ${RUN}`, { type: 'epic' });
    await board.create(`First child ${RUN}`, { parent: first });
    await board.create(`Second child ${RUN}`, { parent: second });

    await page.goto(board.boardUrl);
    await expect(page.locator(READY)).toBeAttached();
    await lane(page, first.id)
        .locator('button[data-action="board-lane#toggle"]')
        .click();
    await expect(lane(page, first.id)).toHaveClass(/lp-board-lane--collapsed/);

    await page.reload();
    await expect(page.locator(READY)).toBeAttached();
    await expect(lane(page, first.id)).toHaveClass(/lp-board-lane--collapsed/);
    await expect(lane(page, second.id)).not.toHaveClass(
        /lp-board-lane--collapsed/,
    );

    await expectBothLanesOpenElsewhere(browser, board, [first, second]);
});

/** A second browser context has its own storage, so it shows every lane open. */
async function expectBothLanesOpenElsewhere(
    browser: Browser,
    board: Board,
    epics: Card[],
): Promise<void> {
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
    });
    try {
        const other = await context.newPage();
        await suppressToolbar(other);
        await suppressWidget(other);
        await login(other, board.email);
        await other.goto(board.boardUrl);
        await expect(other.locator(READY)).toBeAttached();
        for (const epic of epics) {
            await expect(lane(other, epic.id)).toBeVisible();
            await expect(lane(other, epic.id)).not.toHaveClass(
                /lp-board-lane--collapsed/,
            );
        }
    } finally {
        await context.close();
    }
}

test('a drag into another lane changes the parent, and a drag into "Other cards" clears it', async ({
    page,
    board,
}) => {
    const from = await board.create(`From epic ${RUN}`, { type: 'epic' });
    const to = await board.create(`To epic ${RUN}`, { type: 'epic' });
    const child = await board.create(`Moving child ${RUN}`, { parent: from });
    // A card with no parent keeps the "Other cards" row on the board.
    await board.create(`Loose card ${RUN}`);

    await page.goto(board.boardUrl);
    await expect(page.locator(READY)).toBeAttached();
    const next = await columnId(page, 'next');
    const childCard = page.locator(`${CARD}[data-card-id="${child.id}"]`);

    await markBoard(page);
    await watchPlacements(page, child);
    let written = page.waitForResponse((r) => r.url().endsWith('/move'));
    await dragCardToCell(page, child, cell(page, to.id, next));
    await written;
    await cardPlaced(page, childCard);
    await expect(cell(page, to.id, next).locator(childCard)).toHaveCount(1);

    await page.reload();
    await expect(page.locator(READY)).toBeAttached();
    await expect(cell(page, to.id, next).locator(childCard)).toHaveCount(1);

    await markBoard(page);
    await watchPlacements(page, child);
    written = page.waitForResponse((r) => r.url().endsWith('/move'));
    await dragCardToCell(page, child, cell(page, 'other', next));
    await written;
    await cardPlaced(page, childCard);
    await expect(cell(page, 'other', next).locator(childCard)).toHaveCount(1);

    await page.reload();
    await expect(page.locator(READY)).toBeAttached();
    await expect(cell(page, 'other', next).locator(childCard)).toHaveCount(1);
    await expect(childCard.locator('[data-card-parent-tag]')).toHaveCount(0);
});
