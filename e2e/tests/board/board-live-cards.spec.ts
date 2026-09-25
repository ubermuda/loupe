/**
 * Browser coverage for live card changes: a card moved in one browser moves
 * on a second browser's board, marked, with no reload of the board there.
 * The filter and the scroll of the other columns stay. The hub delivers the
 * message, so the run needs a Mercure hub the browser can reach.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBoardLiveCards1!';
const CARD = '[data-board-drag-target="card"]';

async function setFlag(
    request: APIRequestContext,
    name: string,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name, enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
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

function group(page: Page, slug: string) {
    return page.locator(
        `[data-column-slug="${slug}"] [data-board-drag-target="group"]`,
    );
}

test.afterAll(async ({ request }) => {
    await setFlag(request, 'board.enabled', false);
});

test('a card moved in one browser moves in another, marked, with the filter and the scroll kept', async ({
    browser,
    request,
}) => {
    // Five cards go through the create form, one page visit each.
    test.slow();
    await setFlag(request, 'board.enabled', true);
    await setFlag(request, 'live_updates.enabled', true);

    const email = `e2e+livecards+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Live Cards User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const mover = await signedInPage(browser, email, PASSWORD);
    const seeded = await mover.request.post('/dev/seed/document', {
        form: { title: 'E2E Live Cards Project', markdown: '# Live' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = (await seeded.json()).projectId as string;
    const boardUrl = `/projects/${projectId}/board`;

    for (const title of [
        'Alpha live',
        'Bravo live',
        'Charlie live',
        'Delta other',
    ]) {
        await createCard(mover, projectId, title, 'Backlog');
    }
    await createCard(mover, projectId, 'Echo live', 'Next');

    const watcher = await signedInPage(browser, email, PASSWORD);
    // Short, so the Backlog column scrolls its cards.
    await watcher.setViewportSize({ width: 1400, height: 460 });
    await watcher.goto(boardUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(watcher.locator('[data-board-refresh-connected]')).toHaveCount(
        1,
    );

    await watcher.getByRole('searchbox', { name: 'Search cards' }).fill('live');
    const other = watcher.locator(`${CARD}[data-card-title="Delta other"]`);
    await expect(other).toBeHidden();

    const scrolled = await group(watcher, 'backlog').evaluate((element) => {
        element.scrollBy({ top: 30, behavior: 'instant' });
        return element.scrollTop;
    });
    expect(scrolled).toBeGreaterThan(0);

    // A full navigation would drop this marker, and a live placement keeps it.
    await watcher.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });

    const echo = watcher.locator(`${CARD}[data-card-title="Echo live"]`);
    const echoId = await echo.getAttribute('data-card-id');
    expect(echoId).not.toBeNull();

    await mover.goto(`/projects/${projectId}/board/cards/${echoId}`);
    await mover.getByRole('tab', { name: 'Details' }).click();

    const moved = group(watcher, 'done').locator(
        `${CARD}[data-card-title="Echo live"]`,
    );
    // The mark lasts 1.5 s, so watch for it before the mover's page loads.
    const flashed = expect(moved).toHaveClass(/lp-board-card--flash/, {
        timeout: 15000,
    });
    await mover
        .locator('.lp-card-move__select')
        .selectOption({ label: 'Done' });
    await Promise.all([
        flashed,
        expect(mover).toHaveURL(new RegExp(`${boardUrl}$`)),
    ]);
    await expect(group(watcher, 'next').locator(CARD)).toHaveCount(0);
    await expect(
        watcher.locator('[data-column-slug="done"] .lp-board__column-link'),
    ).toHaveText('See the one finished card');

    await expect(other).toBeHidden();
    expect(
        await group(watcher, 'backlog').evaluate(
            (element) => element.scrollTop,
        ),
    ).toBe(scrolled);
    expect(
        await watcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);

    await expect(moved).not.toHaveClass(/lp-board-card--flash/);

    await mover.context().close();
    await watcher.context().close();
});
