/**
 * Browser coverage for the live inbox pill: an answer given in one browser
 * lowers the sidebar count in a second browser with no navigation there, a
 * failed reload keeps the pill it had, and the pill goes away at zero. The hub
 * delivers the signal, so the run needs a hub.
 */

import { test, expect, type APIRequestContext } from '@playwright/test';
import { signedInPage } from '../fixtures';

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

// Two sign-ins, a seed, two answers and three frame loads took 28 seconds on a
// warm local worktree, too close to the 30-second default.
test.setTimeout(60_000);

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

    const editor = await signedInPage(browser, email, PASSWORD);
    const seeded = await editor.request.post('/dev/seed/inbox');
    expect(seeded.status()).toBe(201);
    const { projectId, questionNumber, todoNumber } = await seeded.json();

    const watcher = await signedInPage(browser, email, PASSWORD);
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

    // A reload that fails, as an expired session or a down server would, keeps
    // the pill it had instead of writing Turbo's error into the link.
    const countUrl = `**/projects/${projectId}/inbox/open-count`;
    await watcher.route(countUrl, (route) =>
        // HTML, as a proxy error page is: Turbo ignores any other body.
        route.fulfill({
            status: 502,
            contentType: 'text/html',
            body: '<html><body><h1>502 Bad Gateway</h1></body></html>',
        }),
    );
    const failedReload = watcher.waitForResponse(
        (response) =>
            response.url().endsWith('/inbox/open-count') &&
            response.status() === 502,
    );

    const todo = editor.locator(`#inbox-item-${todoNumber}`);
    await todo.getByRole('button', { name: 'Mark done' }).click();
    // The editor's own pill reloads on the answer, and requests of one session
    // wait on its session lock, so this submit can queue behind that reload.
    await expect(editor.locator('.lp-flash')).toContainText(
        `Item ${todoNumber} is done.`,
        { timeout: 15_000 },
    );

    await failedReload;
    await expect(pill).toHaveText('1');
    await expect(link).not.toContainText('Content missing');
    expect(
        await watcher.evaluate(
            () => (window as unknown as { stayed?: boolean }).stayed,
        ),
    ).toBe(true);

    await watcher.unroute(countUrl);
    await watcher.reload();
    await expect(
        watcher.locator(
            'a[data-controller="inbox-pill"] [data-inbox-open-count]',
        ),
    ).toHaveCount(0);

    await editor.context().close();
    await watcher.context().close();
});
