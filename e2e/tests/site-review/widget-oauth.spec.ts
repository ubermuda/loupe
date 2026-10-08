/**
 * The site-review widget embedded by project, with no token in the page. The
 * reviewer signs in through Loupe's OAuth popup, and the widget then saves
 * comments with the access token it received.
 *
 * The harness embeds the widget with data-project and adds its own origin to
 * the project's allowed sites. The page and Loupe share one origin here, so
 * this spec proves the popup, the consent, the callback's postMessage and the
 * token exchange, but not a cross-origin page. The PHPUnit flow test and the
 * Vitest origin checks cover that part.
 *
 * The fixture signs the file's own user in, and the popup shares its session,
 * so the popup opens straight on the consent page.
 */

import { expect } from '@playwright/test';
import { createTest, suppressToolbar } from '../fixtures';

const EMAIL = 'e2e-site-review-oauth@example.com';

const test = createTest({ email: EMAIL, password: 'E2eSiteReviewOauth1!' });

// Both tests share one account, and each harness load clears its comments.
test.describe.configure({ mode: 'default' });

test('a reviewer signs in through the popup and saves a comment', async ({
    page,
}) => {
    await suppressToolbar(page);
    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(EMAIL)}`,
    );

    // A signed-out widget collapses its quick actions once the boot load
    // ends. A click on Review before that is undone by the collapse.
    await expect(page.locator('#lp-launch-note')).toBeHidden();
    await page.getByRole('button', { name: 'Review' }).click();
    const signIn = page.getByRole('button', { name: 'Sign in with Loupe' });
    await expect(signIn).toBeVisible();

    const [popup] = await Promise.all([
        page.waitForEvent('popup'),
        signIn.click(),
    ]);
    await expect(popup.getByTestId('oauth-consent-project')).toHaveText(
        'e2e-harness',
    );
    await expect(popup.getByTestId('oauth-consent-site')).toHaveText(
        new URL(page.url()).origin,
    );

    const exchange = page.waitForResponse(
        (response) =>
            response.url().endsWith('/oauth/token') &&
            response.request().method() === 'POST',
    );
    await Promise.all([
        popup.waitForEvent('close'),
        popup.getByRole('button', { name: 'Allow' }).click(),
    ]);
    expect((await exchange).status()).toBe(200);

    await expect(signIn).toBeHidden();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await page
        .getByPlaceholder(/Describe the issue/)
        .fill('Signed in with OAuth');
    // A first note asks where notes go.
    await page
        .locator('#lp-picker')
        .getByRole('button', { name: 'A new card for each note' })
        .click();
    const saved = page.waitForResponse(
        (response) =>
            response.url().includes('/api/board/feedback') &&
            response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Save' }).click();
    const response = await saved;
    expect(response.status()).toBe(201);
    expect(response.request().headers()['authorization']).toMatch(/^Bearer ey/);
    await expect(page.locator('#lp-head-count')).toHaveText('1');
});

type CollapseFrame = { width: number; onReview: boolean };
type Sampled = { frames: CollapseFrame[]; done: boolean };

test('the collapsing quick actions never cover the Review button', async ({
    page,
}) => {
    // A signed-out widget collapses its quick actions after the boot load.
    // A style pass before that makes the collapse animate on every run.
    await page.addInitScript(() => {
        const sampled: Sampled = { frames: [], done: false };
        (window as unknown as { __collapse: Sampled }).__collapse = sampled;
        const observer = new MutationObserver(() => {
            const root = [...document.documentElement.children].find(
                (node) => node.shadowRoot,
            )?.shadowRoot;
            const quick = root?.getElementById('lp-launch-quick');
            const review = root?.getElementById('lp-launch-main');
            if (!root || !quick || !review) return;
            observer.disconnect();
            quick.getBoundingClientRect();
            const start = performance.now();
            const sample = (): void => {
                const box = review.getBoundingClientRect();
                const hit = root.elementFromPoint(
                    box.x + box.width / 2,
                    box.y + box.height / 2,
                );
                sampled.frames.push({
                    width: quick.getBoundingClientRect().width,
                    onReview: review.contains(hit),
                });
                if (performance.now() - start < 600) {
                    requestAnimationFrame(sample);
                } else {
                    sampled.done = true;
                }
            };
            requestAnimationFrame(sample);
        });
        observer.observe(document, { childList: true, subtree: true });
    });
    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(EMAIL)}`,
    );

    const read = (): Promise<Sampled> =>
        page.evaluate(
            () => (window as unknown as { __collapse: Sampled }).__collapse,
        );
    await expect.poll(async () => (await read()).done).toBe(true);
    const { frames } = await read();
    expect(frames.some((frame) => frame.width > 0)).toBe(true);
    expect(frames.at(-1)?.width).toBe(0);
    expect(frames.filter((frame) => !frame.onReview)).toEqual([]);
});
