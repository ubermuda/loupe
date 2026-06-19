/**
 * End-to-end happy path for the site-review annotation widget.
 *
 * The dev-only harness page (/dev/site-review-harness) issues a SiteReview API token
 * for a seeded user and loads public/site-review/widget.js with that token. The test
 * enters target mode, clicks a targetable element, types a comment, sends the batch,
 * and asserts a batch id is returned.
 *
 * User creation uses the dev-only /dev/register-and-verify endpoint (registers and
 * immediately marks the email as verified). No login is needed: the harness is
 * PUBLIC_ACCESS and looks the user up by the known e2e email passed in the query string.
 */

import { test, expect } from '@playwright/test';
import { suppressToolbar } from '../fixtures';

// Guest flow — no session cookie should be carried in.
test.use({ storageState: { cookies: [], origins: [] } });

const E2E_EMAIL = 'e2e-site-review@example.com';
const E2E_USERNAME = 'e2esitereview';
const E2E_PASSWORD = 'E2eSiteReview1!';

test('annotate and send a site review batch', async ({ page }) => {
    await suppressToolbar(page);

    // Seed the user the harness will issue a token for (idempotent — re-registering
    // an existing user is handled by the dev endpoint).
    const registerResponse = await page.request.post(
        '/dev/register-and-verify',
        {
            form: {
                username: E2E_USERNAME,
                fullName: 'E2E Site Review',
                email: E2E_EMAIL,
                password: E2E_PASSWORD,
            },
        },
    );
    expect(registerResponse.status()).toBe(200);

    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}`,
    );

    // Clear any pending annotations from a previous run so the counter starts at 0.
    await page.evaluate(() =>
        localStorage.removeItem('betterplans.siteReview.pending'),
    );
    await page.reload();

    // Open the widget panel and enter target mode.
    await page.getByRole('button', { name: /Review \(0\)/ }).click();
    await page.getByRole('button', { name: 'Target mode' }).click();

    // Click the targetable element. The widget's capture-phase click listener resolves
    // the element under the pointer via document.elementFromPoint; the highlight overlay
    // is pointer-events:none so it does not intercept.
    await page.locator('#target-me').click();

    // Type a comment and save it.
    await page.getByPlaceholder(/Comment/).fill('Make this bigger');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
        page.getByRole('button', { name: /Review \(1\)/ }),
    ).toBeVisible();

    // Send the batch and assert a real (UUID-shaped) batch id is returned, not just
    // the success label — a regression returning an empty id would still show the label.
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(page.getByText(/Sent\. Batch id:/)).toBeVisible();
    await expect(page.locator('#result code')).toHaveText(
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    );
});
