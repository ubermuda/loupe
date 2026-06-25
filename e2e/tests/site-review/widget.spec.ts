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

    // The anchored comment renders as a numbered on-screen pin pinned to the element's
    // top-right corner. Playwright pierces the overlay's open shadow root automatically.
    const pin = page.locator('.pin');
    await expect(pin).toHaveText('1');
    const pinBox = await pin.boundingBox();
    const targetBox = await page.locator('#target-me').boundingBox();
    expect(pinBox).not.toBeNull();
    expect(targetBox).not.toBeNull();
    // Pin sits near the target's top-right corner (not stuck at 0,0).
    expect(pinBox!.x).toBeGreaterThan(targetBox!.x);
    expect(
        Math.abs(pinBox!.x - (targetBox!.x + targetBox!.width - 10)),
    ).toBeLessThan(4);
    expect(Math.abs(pinBox!.y - (targetBox!.y - 10))).toBeLessThan(4);

    // SPA navigation must not leave stale pins behind: a client-side URL change (no full
    // reload) hides pins whose annotation belongs to the previous page, and navigating
    // back restores them.
    const originalUrl = page.url();
    await page.evaluate(() =>
        history.pushState({}, '', '/some-other-spa-route'),
    );
    await expect(pin).toBeHidden();
    await page.evaluate((url) => history.pushState({}, '', url), originalUrl);
    await expect(pin).toBeVisible();

    // The toolbar buttons are now icons (inline SVG), the "Site review" header is gone,
    // and the list no longer shows the raw CSS selector.
    await expect(page.locator('#general svg')).toHaveCount(1);
    await expect(page.locator('#target svg')).toHaveCount(1);
    await expect(page.locator('#panel')).not.toContainText('Site review');
    await expect(page.locator('#list code')).toHaveCount(0);

    // Hovering the list row highlights its anchor element on the page.
    const highlight = page.locator('.highlight');
    await expect(highlight).toBeHidden();
    await page.locator('#list .item').first().hover();
    await expect(highlight).toBeVisible();

    // Keyboard shortcut: 't' toggles target mode while the panel is open; Escape cancels.
    const targetButton = page.getByRole('button', { name: 'Target mode' });
    await page.keyboard.press('t');
    await expect(targetButton).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Escape');
    await expect(targetButton).toHaveAttribute('aria-pressed', 'false');

    // Add an unanchored ("general") comment — no element targeting. Because the
    // widget sends the whole batch in one request, the "Sent" assertion below
    // also proves the backend accepts a comment with no selector.
    await page.getByRole('button', { name: 'Add comment' }).click();
    await page
        .getByPlaceholder(/Comment/)
        .fill('A general note about the page');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
        page.getByRole('button', { name: /Review \(2\)/ }),
    ).toBeVisible();

    // Send the batch and assert a real (UUID-shaped) batch id is returned, not just
    // the success label — a regression returning an empty id would still show the label.
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(page.getByText(/Sent\. Batch id:/)).toBeVisible();
    await expect(page.locator('#result code')).toHaveText(
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    );

    // Copying the batch id gives visual confirmation only on a real copy: the button
    // shows "Copied" on success or "Copy failed" otherwise (never false success). The
    // execCommand fallback copies even where the async Clipboard API is unavailable.
    await page.getByRole('button', { name: 'Copy' }).click();
    await expect(page.getByRole('button', { name: 'Copied' })).toBeVisible();
});
