/**
 * Browser coverage for the live board refresh: a column renamed in one browser
 * shows in a second browser on the same board, with no navigation there. The
 * hub delivers the nudge, so the run needs a Mercure hub the browser can reach.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Browser,
    type Page,
} from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

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

async function signedInPage(browser: Browser, email: string): Promise<Page> {
    const { baseURL, extraHTTPHeaders, ignoreHTTPSErrors } =
        test.info().project.use;
    const context = await browser.newContext({
        baseURL,
        extraHTTPHeaders: {},
        ignoreHTTPSErrors,
        storageState: { cookies: [], origins: [] },
        viewport: { width: 1600, height: 900 },
    });
    // The project headers go to the app only. On the hub request a custom
    // header makes the EventSource preflight, which the hub refuses.
    const appOrigin = new URL(baseURL ?? '').origin;
    await context.route(
        (url) => url.origin === appOrigin,
        (route) =>
            route.continue({
                headers: { ...route.request().headers(), ...extraHTTPHeaders },
            }),
    );
    const page = await context.newPage();
    await suppressToolbar(page);
    await suppressWidget(page);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // The first sign-in lands on /welcome, and a later one on the last project.
    await expect(page).not.toHaveURL(/\/login$/);

    return page;
}

async function openBoard(page: Page, boardUrl: string): Promise<void> {
    await page.goto(boardUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(page.locator('[data-board-refresh-connected]')).toHaveCount(1);
}

test.afterAll(async ({ request }) => {
    await setFlag(request, 'board.enabled', false);
});

test('a column renamed in one browser shows in another without a reload', async ({
    browser,
    request,
}) => {
    await setFlag(request, 'board.enabled', true);
    await setFlag(request, 'agent.push.enabled', true);

    const email = `e2e+refresh+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Refresh User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const editor = await signedInPage(browser, email);
    const seeded = await editor.request.post('/dev/seed/document', {
        form: { title: 'E2E Refresh Project', markdown: '# Refresh' },
    });
    expect(seeded.status()).toBe(201);
    const boardUrl = `/projects/${(await seeded.json()).projectId}/board`;

    const watcher = await signedInPage(browser, email);
    await openBoard(editor, boardUrl);
    await openBoard(watcher, boardUrl);

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
