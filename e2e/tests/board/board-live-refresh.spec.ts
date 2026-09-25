/**
 * Browser coverage for the live board refresh: a column renamed in one browser
 * shows in a second browser on the same board, with no navigation there. The
 * hub delivers the nudge, so the run needs a Mercure hub the browser can reach.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBoardRefresh1!';
const COLUMN = '[data-board-columns-target="column"]';

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

async function openBoard(page: Page, boardUrl: string): Promise<void> {
    await page.goto(boardUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(page.locator('[data-board-refresh-connected]')).toHaveCount(1);
}

test.afterAll(async ({ request }) => {
    await setFlag(request, 'board.enabled', true);
});

test('a column renamed in one browser shows in another without a reload', async ({
    browser,
    request,
}) => {
    await setFlag(request, 'board.enabled', true);
    await setFlag(request, 'live_updates.enabled', true);

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
    const renewed = await renewal;
    expect(renewed.status()).toBe(200);
    // The board topic, and the run topic of the card drawer the board hosts.
    const topics: string[] = (await renewed.json()).topics;
    expect(topics).toHaveLength(2);
    expect(topics.some((topic) => topic.endsWith('/board'))).toBe(true);
    expect(topics.some((topic) => topic.endsWith('/worker-runs'))).toBe(true);

    // A full navigation would drop this marker, and a frame reload keeps it.
    await watcher.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });

    const next = editor.locator(`${COLUMN}[data-column-slug="next"]`);
    await next.locator('.lp-board__column-menu-trigger').click();
    await next.getByRole('button', { name: 'Rename' }).click();
    const dialog = editor.locator('dialog[open]');
    await dialog.getByLabel('Name').fill('Up next');
    await dialog.getByRole('button', { name: 'Save name' }).click();
    await expect(
        editor.locator(`${COLUMN}[data-column-slug="up-next"]`),
    ).toHaveCount(1);

    await expect(
        watcher.locator(`${COLUMN}[data-column-slug="up-next"] h2`),
    ).toHaveText('Up next');
    expect(
        await watcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);

    await editor.context().close();
    await watcher.context().close();
});
