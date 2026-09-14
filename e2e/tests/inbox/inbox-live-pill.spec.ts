/**
 * Browser coverage for the live inbox pill: an answer given in one browser
 * lowers the sidebar count in a second browser with no navigation there, and
 * the pill hides at zero. The hub delivers the count, so the run needs a hub.
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
const PASSWORD = 'E2eInboxLivePill1!';

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
    await expect(page).not.toHaveURL(/\/login$/);

    return page;
}

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setFlag(request, 'inbox.enabled', false);
});

test('an answer in one browser lowers the pill in another without a reload', async ({
    browser,
    request,
}) => {
    await setFlag(request, 'inbox.enabled', true);
    await setFlag(request, 'live_updates.enabled', true);

    const email = `e2e+inbox+pill+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Inbox Pill User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const editor = await signedInPage(browser, email);
    const seeded = await editor.request.post('/dev/seed/inbox');
    expect(seeded.status()).toBe(201);
    const { projectId, questionNumber, todoNumber } = await seeded.json();

    const watcher = await signedInPage(browser, email);
    await watcher.goto(`/projects/${projectId}/documents`);
    const link = watcher.locator('a[data-controller="inbox-pill"]');
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(link).toHaveAttribute('data-inbox-pill-connected', '');
    const pill = link.locator('[data-inbox-open-count]');
    await expect(pill).toHaveText('2');

    // A full navigation would drop this marker.
    await watcher.evaluate(() => {
        (window as unknown as { stayed: boolean }).stayed = true;
    });

    await editor.goto(`/projects/${projectId}/inbox`);
    const question = editor.locator(`#inbox-item-${questionNumber}`);
    await question.getByText('CSV', { exact: true }).click();
    await question.getByRole('button', { name: 'Answer' }).click();
    await expect(editor.locator('.lp-flash')).toContainText(
        `Item ${questionNumber} is answered.`,
    );

    await expect(pill).toHaveText('1');

    const todo = editor.locator(`#inbox-item-${todoNumber}`);
    await todo.getByRole('button', { name: 'Mark done' }).click();
    await expect(editor.locator('.lp-flash')).toContainText(
        `Item ${todoNumber} is done.`,
    );

    await expect(pill).toHaveCount(0);
    expect(
        await watcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);

    await editor.context().close();
    await watcher.context().close();
});
