/**
 * Browser coverage for the Up next deck of an epic lane: the count it shows,
 * a drag out of it into a cell, a drop on it that sends a card back to the
 * Backlog, and the slim bar of a collapsed lane.
 *
 * The cards come from the MCP card_create tool, because it sets the type, the
 * column and the parent in one call.
 */

import {
    test as base,
    expect,
    type Locator,
    type Page,
} from '@playwright/test';
import { accessToken, signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eEpicDeck1!';
const READY = '#board[data-board-drag-ready="true"]';
// A live count waits for the move, the card placement and the lane head placement in turn.
const LIVE_UPDATE = { timeout: 15000 };

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
                        status: options.status ?? 'backlog',
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

function lane(page: Page, epic: Card): Locator {
    return page.locator(`.lp-board-lane[data-lane="${epic.id}"]`);
}

function deck(page: Page, epic: Card): Locator {
    return lane(page, epic).locator('.lp-deck');
}

async function nextCell(page: Page, epic: Card): Promise<Locator> {
    const column = await page
        .locator(
            '.lp-board-lane[data-lane="other"] .lp-board-lane__column[data-column-slug="next"] [data-board-drag-target="group"]',
        )
        .getAttribute('data-column');
    expect(column).not.toBeNull();

    return page.locator(
        `[data-board-drag-target="group"][data-lane="${epic.id}"][data-column="${column}"]`,
    );
}

/** Drags from the middle of one element to the middle of another, with pointer events. */
async function drag(page: Page, from: Locator, to: Locator): Promise<void> {
    const start = await from.boundingBox();
    const end = await to.boundingBox();
    expect(start).not.toBeNull();
    expect(end).not.toBeNull();
    if (start === null || end === null) {
        return;
    }
    const x = end.x + end.width / 2;
    const y = end.y + end.height / 2;

    await page.mouse.move(
        start.x + start.width / 2,
        start.y + start.height / 2,
    );
    await page.mouse.down();
    await page.mouse.move(x, y, { steps: 20 });
    await page.mouse.move(x, y + 1, { steps: 4 });
    await page.mouse.up();
}

/** Waits for every transition inside the element to end. */
async function settled(element: Locator): Promise<void> {
    await element.evaluate((node) =>
        Promise.all(
            node.getAnimations({ subtree: true }).map((each) => each.finished),
        ),
    );
}

interface Board {
    page: Page;
    boardUrl: string;
    create: CreateCard;
}

// The board page comes from signedInPage, which keeps the test headers off
// the hub request: a custom header there makes the EventSource preflight.
const test = base.extend<{ board: Board }>({
    board: async ({ browser, request }, use, testInfo) => {
        const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
        const email = `e2e+epic-deck+${tag}+${RUN}@example.com`;
        const registered = await request.post('/dev/register-and-verify', {
            form: { fullName: 'E2E Deck User', email, password: PASSWORD },
        });
        expect(registered.status()).toBe(200);
        const page = await signedInPage(browser, email, PASSWORD);

        const seeded = await page.request.post('/dev/seed/document', {
            form: { title: 'E2E Deck Project', markdown: '# Deck' },
        });
        expect(seeded.status()).toBe(201);
        const projectId = (await seeded.json()).projectId as string;

        await use({
            page,
            boardUrl: `/projects/${projectId}/board`,
            create: await mcpCards(page, projectId),
        });
        await page.context().close();
    },
});

// The fixture and the card_create calls fill most of the default budget on a
// loaded machine, and two tests also wait for the hub to connect.
test.slow();

/** The deck count changes through the hub, which keeps no history, so a move waits for it. */
async function openLive(page: Page, url: string): Promise<void> {
    await page.goto(url);
    await expect(page.locator(READY)).toBeAttached();
    await expect(page.locator('[data-board-live-connected]')).toHaveCount(1, {
        timeout: 15000,
    });
}

test('the deck counts the Backlog cards of its epic, fans them out, and gives one up to a cell', async ({
    board,
}) => {
    const page = board.page;
    const epic = await board.create(`Deck epic ${RUN}`, {
        type: 'epic',
        status: 'next',
    });
    const waiting: Card[] = [];
    for (let index = 1; index <= 5; index++) {
        waiting.push(
            await board.create(`Waiting ${index} ${RUN}`, { parent: epic }),
        );
    }

    await openLive(page, board.boardUrl);
    const label = lane(page, epic).locator('[data-lane-deck-count]');
    await expect(label).toHaveText('5 in Backlog');

    await deck(page, epic).hover();
    for (const card of waiting) {
        await expect(
            deck(page, epic).locator(
                `[data-card-id="${card.id}"] .lp-deck__title`,
            ),
        ).toBeVisible();
    }

    const cell = await nextCell(page, epic);
    await drag(
        page,
        deck(page, epic).locator(`[data-card-id="${waiting[0].id}"]`),
        cell,
    );

    await expect(
        cell.locator(
            `[data-board-drag-target="card"][data-card-id="${waiting[0].id}"]`,
        ),
    ).toHaveCount(1);
    await expect(label).toHaveText('4 in Backlog', LIVE_UPDATE);
    await expect(
        deck(page, epic).locator(`[data-card-id="${waiting[0].id}"]`),
    ).toHaveCount(0);
});

test('a card dropped on the deck of its epic goes back to the Backlog', async ({
    board,
}) => {
    const page = board.page;
    const epic = await board.create(`Return epic ${RUN}`, {
        type: 'epic',
        status: 'next',
    });
    await board.create(`Already waiting ${RUN}`, { parent: epic });
    const working = await board.create(`Working ${RUN}`, {
        parent: epic,
        status: 'next',
    });

    await openLive(page, board.boardUrl);
    const cell = await nextCell(page, epic);
    const face = cell.locator(`[data-card-id="${working.id}"]`);
    await expect(face).toHaveCount(1);
    const label = lane(page, epic).locator('[data-lane-deck-count]');
    await expect(label).toHaveText('1 in Backlog');

    await drag(page, face.locator('.lp-board-card__title'), deck(page, epic));

    // The deck is a bucket, so the card stays hidden in its cell until the move answers.
    await expect(face).toHaveCount(0, LIVE_UPDATE);
    await expect(label).toHaveText('2 in Backlog', LIVE_UPDATE);
    await expect(
        deck(page, epic).locator(`[data-card-id="${working.id}"]`),
    ).toHaveCount(1);

    await page.reload();
    await expect(page.locator(READY)).toBeAttached();
    await expect(
        deck(page, epic).locator(`[data-card-id="${working.id}"]`),
    ).toHaveCount(1);
    await expect(cell.locator(`[data-card-id="${working.id}"]`)).toHaveCount(0);
});

test('a deck card dragged to a cell and back marks its own slot, and the fan stays open on release', async ({
    board,
}) => {
    const page = board.page;
    const epic = await board.create(`Back epic ${RUN}`, {
        type: 'epic',
        status: 'next',
    });
    const waiting: Card[] = [];
    for (let index = 1; index <= 3; index++) {
        waiting.push(
            await board.create(`Back waiting ${index} ${RUN}`, {
                parent: epic,
            }),
        );
    }

    await page.goto(board.boardUrl);
    await expect(page.locator(READY)).toBeAttached();
    const pile = deck(page, epic);
    await pile.hover();
    const lifted = pile.locator(`[data-card-id="${waiting[1].id}"]`);
    await expect(lifted.locator('.lp-deck__title')).toBeVisible();
    // The fan slides in, so its slot is read once it rests.
    await settled(pile);
    const slot = await lifted.boundingBox();
    const cell = await (await nextCell(page, epic)).boundingBox();
    expect(slot).not.toBeNull();
    expect(cell).not.toBeNull();
    if (slot === null || cell === null) {
        return;
    }
    const home = { x: slot.x + slot.width / 2, y: slot.y + slot.height / 2 };

    await page.mouse.move(home.x, home.y);
    await page.mouse.down();
    await page.mouse.move(cell.x + cell.width / 2, cell.y + cell.height / 2, {
        steps: 20,
    });
    await page.mouse.move(home.x, home.y, { steps: 20 });

    const ghost = pile.locator('.lp-board__ghost');
    await expect(ghost).toBeVisible();
    await settled(pile);
    const marker = await ghost.boundingBox();
    expect(Math.abs((marker?.x ?? 0) - slot.x)).toBeLessThan(8);
    await expect(
        pile.locator(`[data-card-id="${waiting[0].id}"]`),
    ).not.toHaveCSS('border-top-style', 'dashed');

    await page.mouse.up();
    // The card travels from the release point, not from the pile at the right.
    const landing = await lifted.evaluate((element) => ({
        x: element.getBoundingClientRect().x,
        slides: element
            .getAnimations()
            .some(
                (animation) =>
                    animation instanceof CSSTransition &&
                    animation.transitionProperty === 'translate',
            ),
    }));
    expect(landing.slides).toBe(false);
    expect(Math.abs(landing.x - slot.x)).toBeLessThan(40);
    await expect(pile).toHaveClass(/lp-deck--open/);
    await settled(pile);
    for (const card of waiting) {
        await expect(
            pile.locator(`[data-card-id="${card.id}"] .lp-deck__title`),
        ).toBeVisible();
    }
});

test('a collapsed lane is a slim bar with its number, title and progress only', async ({
    board,
}) => {
    const page = board.page;
    const epic = await board.create(`Slim epic ${RUN}`, {
        type: 'epic',
        status: 'next',
    });
    await board.create(`Slim waiting ${RUN}`, { parent: epic });
    await board.create(`Slim working ${RUN}`, { parent: epic, status: 'next' });

    await page.goto(board.boardUrl);
    await expect(page.locator(READY)).toBeAttached();
    const epicLane = lane(page, epic);
    await expect(deck(page, epic)).toBeVisible();

    await epicLane.locator('button[data-action="board-lane#toggle"]').click();

    await expect(epicLane).toHaveClass(/lp-board-lane--collapsed/);
    await expect(epicLane.locator('.lp-board-lane__title')).toBeVisible();
    await expect(epicLane.locator('.lp-board-lane__title')).toHaveText(
        `Slim epic ${RUN}`,
    );
    await expect(epicLane.locator('.lp-board-lane__number')).toHaveText(
        `#${epic.number}`,
    );
    await expect(epicLane.locator('.lp-board-lane__bar')).toBeVisible();
    await expect(epicLane.locator('.lp-board-lane__done')).toHaveCSS(
        'opacity',
        '0',
    );
    await expect(epicLane.locator('.lp-board-lane__up-next')).toBeHidden();
    await expect(epicLane.locator('.lp-board-lane__cells')).toBeHidden();
    await expect(
        epicLane.getByRole('button', { name: 'Hide the lane on the board' }),
    ).toBeHidden();
    const bar = await epicLane.locator('.lp-board-lane__head').boundingBox();
    expect(bar?.height).toBeLessThanOrEqual(56);

    await epicLane.locator('button[data-action="board-lane#toggle"]').click();
    await expect(deck(page, epic)).toBeVisible();
    await expect(epicLane.locator('.lp-board-lane__cells')).toBeVisible();
});

test('the deck stays in view while the lanes scroll sideways, and a long title wraps clear of it', async ({
    board,
}) => {
    const page = board.page;
    await page.setViewportSize({ width: 640, height: 800 });
    const epic = await board.create(
        `Pinned epic with a title long enough to need two lines in the lane head ${RUN}`,
        { type: 'epic', status: 'next' },
    );
    await board.create(`Pinned waiting ${RUN}`, { parent: epic });

    await page.goto(board.boardUrl);
    await expect(page.locator(READY)).toBeAttached();
    const scroller = page.locator('.lp-board__columns--lanes');
    const upNext = lane(page, epic).locator('.lp-board-lane__up-next');
    await expect(upNext).toBeVisible();

    const strip = await scroller.evaluate((element) => ({
        scrollWidth: element.scrollWidth,
        clientWidth: element.clientWidth,
        right: element.getBoundingClientRect().right,
    }));
    expect(strip.scrollWidth).toBeGreaterThan(strip.clientWidth);
    const pinned = await upNext.boundingBox();
    expect(pinned).not.toBeNull();
    if (pinned === null) {
        return;
    }
    expect(pinned.x + pinned.width).toBeLessThanOrEqual(strip.right + 1);

    const title = await lane(page, epic)
        .locator('.lp-board-lane__title')
        .boundingBox();
    const head = await lane(page, epic)
        .locator('.lp-board-lane__head')
        .boundingBox();
    expect(title).not.toBeNull();
    expect(head).not.toBeNull();
    if (title === null || head === null) {
        return;
    }
    expect(title.x + title.width).toBeLessThanOrEqual(pinned.x);
    expect(title.y + title.height).toBeLessThanOrEqual(head.y + head.height);

    await scroller.evaluate((element) => {
        element.scrollLeft = element.scrollWidth;
    });
    const scrolled = await upNext.boundingBox();
    expect(scrolled).not.toBeNull();
    expect((scrolled?.x ?? 0) + (scrolled?.width ?? 0)).toBeLessThanOrEqual(
        strip.right + 1,
    );
});
