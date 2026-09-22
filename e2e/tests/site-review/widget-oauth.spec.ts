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

test('a reviewer signs in through the popup and saves a comment', async ({
    page,
}) => {
    await suppressToolbar(page);
    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(EMAIL)}`,
    );

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
    const saved = page.waitForResponse(
        (response) =>
            response.url().includes('/api/site-review/comments') &&
            response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Save' }).click();
    const response = await saved;
    expect(response.status()).toBe(201);
    expect(response.request().headers()['authorization']).toMatch(/^Bearer ey/);
    await expect(page.locator('#lp-head-count')).toHaveText('1');
});
