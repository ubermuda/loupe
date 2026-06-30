/**
 * End-to-end happy path for the (redesigned) site-review annotation widget.
 *
 * The dev-only harness page (/dev/site-review-harness) issues a SiteReview API token
 * for a seeded user and loads public/site-review/widget.js with that token. The test
 * opens the panel, enters pick mode, clicks a targetable element, types a comment,
 * adds a general note, sends the batch, and asserts a real batch id is returned.
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

    // The collapsed launcher carries no count badge when empty.
    const launcher = page.getByRole('button', { name: 'Review' });
    await expect(launcher).toBeVisible();
    await expect(page.locator('#bp-launch-count')).toBeHidden();

    // Open the widget panel and enter pick (element-target) mode.
    await launcher.click();
    await expect(page.locator('#bp-panel')).toBeVisible();
    await page.getByRole('button', { name: 'Pick element' }).click();

    // Click the targetable element. The widget's capture-phase click listener resolves
    // the element under the pointer via document.elementFromPoint; the scrim/highlight
    // overlay is pointer-events:none so it does not intercept.
    await page.locator('#target-me').click();

    // Type a comment and save it.
    await page.getByPlaceholder(/Describe the issue/).fill('Make this bigger');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#bp-head-count')).toHaveText('1');

    // The anchored comment renders as a numbered on-screen pin pinned to the element's
    // top-right corner. Playwright pierces the overlay's open shadow root automatically.
    const pin = page.locator('.pin');
    await expect(pin).toHaveText('1');
    // The pin drops in with a scale animation; wait for it to settle before measuring
    // position, otherwise boundingBox() captures a mid-transform (scaled) rect.
    await expect
        .poll(() => pin.evaluate((el) => getComputedStyle(el).transform))
        .toBe('none');
    const pinBox = await pin.boundingBox();
    const targetBox = await page.locator('#target-me').boundingBox();
    expect(pinBox).not.toBeNull();
    expect(targetBox).not.toBeNull();
    // Pin sits near the target's top-right corner (not stuck at 0,0).
    expect(pinBox!.x).toBeGreaterThan(targetBox!.x);
    expect(
        Math.abs(pinBox!.x - (targetBox!.x + targetBox!.width - 12)),
    ).toBeLessThan(4);
    expect(Math.abs(pinBox!.y - (targetBox!.y - 12))).toBeLessThan(4);

    // Hovering a pin reveals its popover (with the comment body) and grows — never
    // shrinks — the badge. (Regression: a re-render that recreated the pin replayed
    // the scale-in animation and made it briefly collapse.)
    await pin.hover();
    const popover = page.locator('.bp-pop');
    await expect(popover).toBeVisible();
    await expect(popover).toContainText('Make this bigger');
    const hoveredBox = await pin.boundingBox();
    expect(hoveredBox!.width).toBeGreaterThanOrEqual(pinBox!.width - 0.5);

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

    // The action buttons are icon + label (inline SVG), the panel title is "Review"
    // (no "Site review" header), and the list never shows the raw CSS selector.
    await expect(page.locator('#general svg')).toHaveCount(1);
    await expect(page.locator('#target svg')).toHaveCount(1);
    await expect(page.locator('#bp-panel')).not.toContainText('Site review');
    await expect(page.locator('#bp-list code')).toHaveCount(0);

    // Hovering the list row highlights its anchor element on the page. The list is
    // collapsed by default, so expand it first to reveal the row. Park the pointer on a
    // neutral element first so the subsequent hover crosses a boundary and emits the
    // mouseenter the highlight listens for.
    const highlight = page.locator('.highlight');
    await page.locator('#bp-list-toggle').click();
    await page.locator('#bp-close').hover();
    await expect(highlight).toBeHidden();
    await page.locator('#bp-list .bp-item').first().hover();
    await expect(highlight).toBeVisible();

    // Keyboard shortcut: 't' toggles pick mode while the panel is open. Entering pick
    // mode hides the whole widget (launcher + panel) so it does not obscure the page and
    // shows the pick toast; Escape cancels and restores the panel.
    await page.keyboard.press('t');
    await expect(page.locator('#bp-panel')).toBeHidden();
    await expect(page.locator('#bp-launcher')).toBeHidden();
    await expect(page.locator('#bp-toast')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#bp-panel')).toBeVisible();
    await expect(page.locator('#bp-toast')).toBeHidden();

    // Add an unanchored ("general") comment — no element targeting. Because the
    // widget sends the whole batch in one request, the "Sent" assertion below
    // also proves the backend accepts a comment with no selector.
    await page.getByRole('button', { name: 'Add note' }).click();
    await page
        .getByPlaceholder(/Describe the issue/)
        .fill('A general note about the page');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#bp-head-count')).toHaveText('2');

    // Send the batch and assert a real (UUID-shaped) batch id is returned, not just
    // the success label — a regression returning an empty id would still show the label.
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(page.getByText('Review sent')).toBeVisible();
    await expect(page.locator('#bp-panel code')).toHaveText(
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    );

    // Copying the batch id gives visual confirmation only on a real copy: the button
    // shows "Copied" on success or "Copy failed" otherwise (never false success). The
    // execCommand fallback copies even where the async Clipboard API is unavailable.
    await page.getByRole('button', { name: 'Copy' }).click();
    await expect(page.getByRole('button', { name: 'Copied' })).toBeVisible();

    // Re-rendering the sent panel (clicking Copy again) must reconcile, not rebuild:
    // re-assigning innerHTML would recreate .bp-sent and replay its entrance animation
    // across the whole panel. Tag the node and confirm a second render preserves it.
    await page.locator('.bp-sent').evaluate((el: HTMLElement) => {
        el.dataset.tag = 'orig';
    });
    await page.locator('#bp-copy').click();
    await expect(page.locator('.bp-sent[data-tag="orig"]')).toHaveCount(1);
});

test('a failed send keeps the batch and offers retry', async ({ page }) => {
    await suppressToolbar(page);

    // Seed the user (idempotent) and open the harness with one pending comment.
    await page.request.post('/dev/register-and-verify', {
        form: {
            username: E2E_USERNAME,
            fullName: 'E2E Site Review',
            email: E2E_EMAIL,
            password: E2E_PASSWORD,
        },
    });
    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}`,
    );
    await page.evaluate(() =>
        localStorage.setItem(
            'betterplans.siteReview.pending',
            JSON.stringify([
                {
                    body: 'A general note about the page',
                    selector: '',
                    text: '',
                    url: location.href,
                },
            ]),
        ),
    );
    await page.reload();

    // Make the backend reject the batch.
    let calls = 0;
    await page.route('**/api/site-review/batches', (route) => {
        calls += 1;
        void route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'boom' }),
        });
    });

    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: 'Send' }).click();

    // The error banner appears, and — critically — the pending batch is NOT cleared,
    // so the reviewer can retry rather than losing their feedback.
    await expect(page.getByText(/send your review/i)).toBeVisible();
    await expect(page.locator('#bp-head-count')).toHaveText('1');
    expect(calls).toBe(1);

    // "Try again" re-fires the send.
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(page.getByText(/send your review/i)).toBeVisible();
    await expect.poll(() => calls).toBe(2);
    await expect(page.locator('#bp-head-count')).toHaveText('1');
});

test('deleting a list comment uses a sliding confirm overlay', async ({
    page,
}) => {
    await suppressToolbar(page);
    await page.request.post('/dev/register-and-verify', {
        form: {
            username: E2E_USERNAME,
            fullName: 'E2E Site Review',
            email: E2E_EMAIL,
            password: E2E_PASSWORD,
        },
    });
    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}`,
    );
    await page.evaluate(() =>
        localStorage.setItem(
            'betterplans.siteReview.pending',
            JSON.stringify([
                {
                    body: 'First note',
                    selector: '',
                    text: '',
                    url: location.href,
                },
                {
                    body: 'Second note',
                    selector: '',
                    text: '',
                    url: location.href,
                },
            ]),
        ),
    );
    await page.reload();
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: /Show .* comments/ }).click();

    const row = page.locator('#bp-list .bp-item').first();
    // Tag the row node so we can prove it is not rebuilt while arming/cancelling.
    await row.evaluate((el: HTMLElement) => {
        el.dataset.tag = 'orig';
    });

    // Arming delete slides a confirm overlay over the row (not an inline swap).
    await row.locator('.bp-del').click();
    const confirm = row.locator('.bp-item-confirm');
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText('Delete this comment?');
    expect(await row.evaluate((el: HTMLElement) => el.dataset.tag)).toBe(
        'orig',
    );

    // Cancel removes the overlay (after sliding out) and deletes nothing.
    await confirm.locator('button', { hasText: 'Cancel' }).click();
    await expect(row.locator('.bp-item-confirm')).toHaveCount(0);
    expect(await row.evaluate((el: HTMLElement) => el.dataset.tag)).toBe(
        'orig',
    );
    await expect(page.locator('#bp-head-count')).toHaveText('2');

    // Confirming actually deletes the comment.
    await row.locator('.bp-del').click();
    await row
        .locator('.bp-item-confirm')
        .locator('button', { hasText: 'Delete' })
        .click();
    await expect(page.locator('#bp-head-count')).toHaveText('1');
});

test('re-executing the script does not stack a second widget', async ({
    page,
}) => {
    await suppressToolbar(page);
    await page.request.post('/dev/register-and-verify', {
        form: {
            username: E2E_USERNAME,
            fullName: 'E2E Site Review',
            email: E2E_EMAIL,
            password: E2E_PASSWORD,
        },
    });
    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}`,
    );
    await expect(page.locator('#bp-launcher')).toHaveCount(1);

    // SPA frameworks (e.g. Turbo) re-execute body <script> tags on navigation. The
    // widget's hosts live on <html> and survive a <body> swap, so a second init would
    // stack another launcher (and its shadow). Re-injecting the script must be a no-op.
    await page.evaluate(
        () =>
            new Promise<void>((resolve) => {
                const s = document.createElement('script');
                s.src = '/site-review/widget.js';
                s.setAttribute('data-token', 'x');
                s.onload = () => resolve();
                document.body.appendChild(s);
            }),
    );
    await expect(page.locator('#bp-launcher')).toHaveCount(1);
});
