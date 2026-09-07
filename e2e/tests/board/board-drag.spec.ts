/**
 * Browser coverage for dragging a card on the board.
 *
 * A drag inside one priority group reorders it, and a drag into another column
 * changes the card's status. Both assert the order again after a reload,
 * because a drop moves the card in the page before the server has answered, and
 * a reload is what shows whether the server agreed.
 *
 * Two more cases guard the parts a drop can get wrong. A request that never
 * arrives must put the card back and say so. A click on the card title, which
 * is a link inside the drag handle, must still open the card.
 */

import {
    test as base,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBoardDrag1!';

const BACKLOG = 0;
const NEXT = 1;
const HIGH = 0;

const CARD = '[data-board-drag-target="card"]';
const GROUP = '[data-board-drag-target="group"]';
// The drag controller sets this when it connects. A grab before that reaches no
// listener, so every drag waits for it rather than for the cards alone.
const READY = '#board[data-board-drag-ready="true"]';

async function waitForDragReady(page: Page): Promise<void> {
    await expect(page.locator(READY)).toBeAttached();
}

async function setBoardFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function devRegisterAndVerify(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Board User', email, password },
    });
    expect(response.status()).toBe(200);
}

async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome');
}

/** Seeds a document only for the project it creates, which the board needs. */
async function seedProject(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Board Project', markdown: '# Board' },
    });
    expect(response.status()).toBe(201);

    return (await response.json()).projectId as string;
}

async function createCard(
    page: Page,
    projectId: string,
    title: string,
): Promise<void> {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill(title);
    await page.getByLabel('Priority').selectOption('10');
    await page.getByLabel('Column').selectOption('backlog');
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
}

function group(page: Page, column: number, priority: number) {
    return page
        .locator('.lp-board__column')
        .nth(column)
        .locator(GROUP)
        .nth(priority);
}

/**
 * The card titles in one group. The link also carries the card's per-project
 * number, so each title is read from the card's own data attribute instead.
 */
function titlesIn(page: Page, column: number, priority: number) {
    return group(page, column, priority)
        .locator('[data-board-drag-target="card"]')
        .evaluateAll((cards) =>
            cards.map((card) => card.getAttribute('data-card-title') ?? ''),
        );
}

/**
 * Drags a card by its title to a point, with pointer events rather than the
 * HTML5 drag API, which is what the controller listens for and what Playwright
 * drives deterministically. The title is a link, so this also proves that a
 * drag which starts on it moves the card rather than opening it.
 */
async function dragCardTo(
    page: Page,
    title: string,
    target: { x: number; y: number },
): Promise<void> {
    const grip = page.locator(
        `[data-card-title="${title}"] .lp-board-card__title`,
    );
    const from = await grip.boundingBox();
    expect(from).not.toBeNull();
    if (from === null) {
        return;
    }

    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(target.x, target.y, { steps: 20 });
    await page.mouse.move(target.x, target.y + 1, { steps: 4 });
    await page.mouse.up();
}

/**
 * Resolves when the move endpoint has answered.
 *
 * A drop moves the card in the page before it posts, so the order on screen is
 * true the moment the drag ends. A reload asserted before the answer would race
 * the write, and pass or fail with the load on the stack.
 */
function movePosted(page: Page): Promise<unknown> {
    return page.waitForResponse((response) => response.url().endsWith('/move'));
}

async function topEdgeOf(
    page: Page,
    title: string,
): Promise<{ x: number; y: number }> {
    const box = await page
        .locator(`[data-card-title="${title}"]`)
        .boundingBox();
    expect(box).not.toBeNull();
    if (box === null) {
        throw new Error(`no box for ${title}`);
    }

    return { x: box.x + box.width / 2, y: box.y + 4 };
}

async function centreOfGroup(
    page: Page,
    column: number,
    priority: number,
): Promise<{ x: number; y: number }> {
    const box = await group(page, column, priority).boundingBox();
    expect(box).not.toBeNull();
    if (box === null) {
        throw new Error('no box for group');
    }

    return { x: box.x + box.width / 2, y: box.y + box.height / 2 };
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
            const email = `e2e+board+${tag}+${RUN}@example.com`;

            await devRegisterAndVerify(page, email, PASSWORD);
            await login(page, email, PASSWORD);

            const projectId = await seedProject(page);
            await createCard(page, projectId, 'Alpha');
            await createCard(page, projectId, 'Bravo');

            const boardUrl = `/projects/${projectId}/board`;
            await page.goto(boardUrl);
            await expect(page.locator(CARD)).toHaveCount(2);
            await waitForDragReady(page);

            await use({ projectId, boardUrl });
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

// The flag is global, so it goes back off: left on, it would change what every
// later spec's sidebar and routing table look like.
test.afterAll(async ({ request }) => {
    await setBoardFlag(request, false);
});

test('a drag inside a priority group reorders it, and the order survives a reload', async ({
    page,
    board,
}) => {
    expect(await titlesIn(page, BACKLOG, HIGH)).toEqual(['Alpha', 'Bravo']);

    const written = movePosted(page);
    await dragCardTo(page, 'Bravo', await topEdgeOf(page, 'Alpha'));
    await written;

    await expect
        .poll(() => titlesIn(page, BACKLOG, HIGH))
        .toEqual(['Bravo', 'Alpha']);

    await page.goto(board.boardUrl);
    await waitForDragReady(page);
    expect(await titlesIn(page, BACKLOG, HIGH)).toEqual(['Bravo', 'Alpha']);
});

test('a drag into another column changes the status, and it survives a reload', async ({
    page,
    board,
}) => {
    const written = movePosted(page);
    await dragCardTo(page, 'Alpha', await centreOfGroup(page, NEXT, HIGH));
    await written;

    await expect.poll(() => titlesIn(page, NEXT, HIGH)).toEqual(['Alpha']);
    expect(await titlesIn(page, BACKLOG, HIGH)).toEqual(['Bravo']);

    await page.goto(board.boardUrl);
    expect(await titlesIn(page, NEXT, HIGH)).toEqual(['Alpha']);
    expect(await titlesIn(page, BACKLOG, HIGH)).toEqual(['Bravo']);
});

test('a move the server never receives puts the card back and says so', async ({
    page,
    board,
}) => {
    expect(await titlesIn(page, BACKLOG, HIGH)).toEqual(['Alpha', 'Bravo']);

    await page.route('**/board/cards/*/move', (route) => route.abort());
    await dragCardTo(page, 'Alpha', await centreOfGroup(page, NEXT, HIGH));

    const message = page.locator('.lp-board__message');
    await expect(message).toHaveText(/./);
    expect(await titlesIn(page, BACKLOG, HIGH)).toEqual(['Alpha', 'Bravo']);
    expect(await titlesIn(page, NEXT, HIGH)).toEqual([]);

    await page.unroute('**/board/cards/*/move');
    await page.goto(board.boardUrl);
    await waitForDragReady(page);
    expect(await titlesIn(page, BACKLOG, HIGH)).toEqual(['Alpha', 'Bravo']);
});

test("the card's own page moves it without a pointer", async ({
    page,
    board,
}) => {
    // The board face offers dragging and nothing else, so the keyboard path to
    // the same endpoint is the form on the card page.
    await page.locator('[data-card-title="Bravo"] a').click();
    await expect(page.getByRole('button', { name: 'Move card' })).toBeVisible();

    await page.getByLabel('Column').selectOption('next');
    await page.getByRole('button', { name: 'Move card' }).click();

    await expect(page).toHaveURL(board.boardUrl);
    await waitForDragReady(page);
    expect(await titlesIn(page, NEXT, HIGH)).toEqual(['Bravo']);

    await page.goto(board.boardUrl);
    expect(await titlesIn(page, NEXT, HIGH)).toEqual(['Bravo']);
});
