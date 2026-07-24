/**
 * End-to-end tests for the server-backed site-review annotation widget.
 *
 * The dev-only harness page (/dev/site-review-harness) finds-or-creates the
 * `e2e-harness` site for the user, deletes any in-progress draft review and
 * mints a fresh site-bound token on every load — so each test starts from a
 * clean draft simply by loading the harness (no localStorage involved).
 *
 * The widget is server-backed: every saved comment POSTs immediately to
 * /api/site-review/comments, the list rehydrates from GET /api/site-review/review
 * on load, edits PATCH, deletes DELETE, and "Send" submits the in-progress
 * review via POST /api/site-review/review/submit.
 *
 * User creation uses the dev-only /dev/register-and-verify endpoint (registers and
 * immediately marks the email as verified). No login is needed: the harness is
 * PUBLIC_ACCESS and looks the user up by the known e2e email passed in the query string.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar } from '../fixtures';

// Guest flow — no session cookie should be carried in.
test.use({ storageState: { cookies: [], origins: [] } });

const E2E_EMAIL = 'e2e-site-review@example.com';
const E2E_USERNAME = 'e2esitereview';
const E2E_PASSWORD = 'E2eSiteReview1!';

/**
 * Seed the user (idempotent — re-registering an existing user is handled by the
 * dev endpoint) and load the harness. Every load resets the draft server-side.
 */
const openHarness = async (page: Page): Promise<void> => {
    await suppressToolbar(page);
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
};

/**
 * Add a general (unanchored) note through the panel UI. The panel must already
 * be open. Waits for the head count so the server POST has landed before the
 * test moves on.
 */
const addGeneralNote = async (
    page: Page,
    body: string,
    expectedCount: string,
): Promise<void> => {
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await page.getByPlaceholder(/Describe the issue/).fill(body);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText(expectedCount);
};

/**
 * Read the in-progress review straight from the API using the widget's own
 * token (from the script tag). This proves server persistence without
 * reloading — a harness reload deliberately purges the draft, so "survives
 * reload" cannot be asserted against the harness.
 */
const fetchReviewComments = (
    page: Page,
): Promise<Array<{ body: string; selector: string }>> =>
    page.evaluate(async () => {
        const script = document.querySelector(
            'script[src*="site-review/widget.js"]',
        )!;
        const token = script.getAttribute('data-token')!;
        const response = await fetch('/api/site-review/review', {
            headers: { Authorization: `Bearer ${token}` },
        });
        const { review } = (await response.json()) as {
            review: {
                comments: Array<{ body: string; selector: string }>;
            } | null;
        };
        return review ? review.comments : [];
    });

test('annotate and send a site review', async ({ page }) => {
    await openHarness(page);

    // The collapsed launcher carries no count badge when empty (the harness
    // reset the draft, so the boot rehydrate finds nothing).
    const launcher = page.getByRole('button', { name: 'Review' });
    await expect(launcher).toBeVisible();
    await expect(page.locator('#lp-launch-count')).toBeHidden();

    // Open the widget panel and enter pick (element-target) mode.
    await launcher.click();
    await expect(page.locator('#lp-panel')).toBeVisible();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();

    // Click the targetable element. The widget's capture-phase click listener resolves
    // the element under the pointer via document.elementFromPoint; the scrim/highlight
    // overlay is pointer-events:none so it does not intercept.
    await page.locator('#target-me').click();

    // Type a comment and save it (the save POSTs to the server immediately).
    await page.getByPlaceholder(/Describe the issue/).fill('Make this bigger');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');

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
    const popover = page.locator('.lp-pop');
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
    await expect(page.locator('#lp-panel')).not.toContainText('Site review');
    await expect(page.locator('#lp-list code')).toHaveCount(0);

    // Hovering the list row highlights its anchor element on the page. The list is
    // collapsed by default, so expand it first to reveal the row. Park the pointer on a
    // neutral element first so the subsequent hover crosses a boundary and emits the
    // mouseenter the highlight listens for.
    const highlight = page.locator('.highlight');
    await page.locator('#lp-list-toggle').click();
    await page.locator('#lp-close').hover();
    await expect(highlight).toBeHidden();
    await page.locator('#lp-list .lp-item').first().hover();
    await expect(highlight).toBeVisible();

    // Keyboard shortcut: 't' toggles pick mode while the panel is open. Entering pick
    // mode hides the whole widget (launcher + panel) so it does not obscure the page and
    // shows the pick toast; Escape cancels and restores the panel.
    await page.keyboard.press('t');
    await expect(page.locator('#lp-panel')).toBeHidden();
    await expect(page.locator('#lp-launcher')).toBeHidden();
    await expect(page.locator('#lp-toast')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#lp-panel')).toBeVisible();
    await expect(page.locator('#lp-toast')).toBeHidden();

    // Add an unanchored ("general") comment — no element targeting. The count
    // reaching 2 proves the backend accepted a comment with no selector.
    await addGeneralNote(page, 'A general note about the page', '2');

    // Both comments were persisted server-side as they were saved — the core new
    // behaviour. Read the in-progress review back through the widget's token.
    const persisted = await fetchReviewComments(page);
    expect(persisted.map((comment) => comment.body).sort()).toEqual([
        'A general note about the page',
        'Make this bigger',
    ]);

    // Send the review. The sent panel confirms and hands off to the agent — it
    // exposes no batch id and no Copy button (both are gone in the server-backed
    // flow), only a way to start over.
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(page.getByText('Review sent')).toBeVisible();
    await expect(page.getByText('Your agent has been notified')).toBeVisible();
    await expect(page.locator('#lp-panel code')).toHaveCount(0); // no id to copy
    await expect(page.getByRole('button', { name: 'Copy' })).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Start a new review' }),
    ).toBeVisible();
});

test('a keep=1 reload rehydrates the server draft into pins and list', async ({
    page,
}) => {
    // First load purges any leftover draft; then move to the keep=1 URL and do
    // all the annotating THERE — pins only render when the comment's stored url
    // matches location.href, so the save and the reload must share the URL.
    await openHarness(page);
    const keepUrl = `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}&keep=1`;
    await page.goto(keepUrl);

    // Seed one anchored comment + one general note through the UI.
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page.getByPlaceholder(/Describe the issue/).fill('Make this bigger');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    await addGeneralNote(page, 'A general note about the page', '2');

    // Reload the harness with keep=1: the draft survives (only the token is
    // re-minted; the draft belongs to the site, not the token) and the widget
    // boots by rehydrating from GET /api/site-review/review.
    await page.goto(keepUrl);

    // The launcher badge shows the rehydrated count without any interaction.
    await expect(page.locator('#lp-launch-count')).toHaveText('2');

    // Expanding the list shows both bodies.
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: /Show .* comments/ }).click();
    await expect(page.locator('#lp-list')).toContainText('Make this bigger');
    await expect(page.locator('#lp-list')).toContainText(
        'A general note about the page',
    );

    // The anchored comment's pin re-appears, positioned on #target-me.
    const pin = page.locator('.pin');
    await expect(pin).toHaveText('1');
    await expect
        .poll(() => pin.evaluate((el) => getComputedStyle(el).transform))
        .toBe('none');
    const pinBox = await pin.boundingBox();
    const targetBox = await page.locator('#target-me').boundingBox();
    expect(pinBox).not.toBeNull();
    expect(targetBox).not.toBeNull();
    expect(
        Math.abs(pinBox!.x - (targetBox!.x + targetBox!.width - 12)),
    ).toBeLessThan(4);
    expect(Math.abs(pinBox!.y - (targetBox!.y - 12))).toBeLessThan(4);
});

test('a failed send keeps the review and offers retry', async ({ page }) => {
    await openHarness(page);

    // Seed one comment through the UI (it POSTs to the server immediately).
    await page.getByRole('button', { name: 'Review' }).click();
    await addGeneralNote(page, 'A general note about the page', '1');

    // Make the backend reject the submit.
    let calls = 0;
    await page.route('**/api/site-review/review/submit', (route) => {
        calls += 1;
        void route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'boom' }),
        });
    });

    await page.getByRole('button', { name: 'Send' }).click();

    // The error banner appears, and — critically — the draft is NOT cleared,
    // so the reviewer can retry rather than losing their feedback.
    const panel = page.locator('#lp-panel');
    await expect(panel.getByText(/send your review/i)).toBeVisible();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    expect(calls).toBe(1);

    // "Try again" re-fires the send.
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(panel.getByText(/send your review/i)).toBeVisible();
    await expect.poll(() => calls).toBe(2);
    await expect(page.locator('#lp-head-count')).toHaveText('1');
});

test('a permanent 403 on save explains the token problem instead of offering retry', async ({
    page,
}) => {
    await openHarness(page);

    // Force the comment POST to fail the way an invalid / unlinked widget token does:
    // a 403 no amount of retrying can clear.
    await page.route('**/api/site-review/comments', (route) => {
        void route.fulfill({
            status: 403,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'token_not_bound_to_site' }),
        });
    });

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await page.getByPlaceholder(/Describe the issue/).fill('Anything at all');
    await page.getByRole('button', { name: 'Save' }).click();

    const panel = page.locator('#lp-panel');
    // The banner names the cause (token not linked to the site) rather than the generic
    // "couldn't apply that change, try again" — and offers Dismiss, not a doomed retry.
    await expect(panel.getByText(/linked to this site/i)).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Dismiss' })).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Try again' })).toHaveCount(
        0,
    );

    // The draft is preserved in the open composer so nothing the reviewer wrote is lost.
    await expect(page.getByPlaceholder(/Describe the issue/)).toHaveValue(
        'Anything at all',
    );
});

test('a 401 on save reports an invalid token rather than a generic retry', async ({
    page,
}) => {
    await openHarness(page);

    // Force the comment POST to fail the way an invalid / revoked token does: a 401
    // that retrying can never clear.
    await page.route('**/api/site-review/comments', (route) => {
        void route.fulfill({
            status: 401,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'unauthorized' }),
        });
    });

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await page.getByPlaceholder(/Describe the issue/).fill('Anything at all');
    await page.getByRole('button', { name: 'Save' }).click();

    const panel = page.locator('#lp-panel');
    // The banner names the cause (invalid / revoked token) and offers Dismiss, not a
    // doomed retry.
    await expect(panel.getByText(/invalid or was revoked/i)).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Dismiss' })).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Try again' })).toHaveCount(
        0,
    );

    // The draft is preserved in the open composer so nothing the reviewer wrote is lost.
    await expect(page.getByPlaceholder(/Describe the issue/)).toHaveValue(
        'Anything at all',
    );
});

test('deleting a list comment uses a sliding confirm overlay', async ({
    page,
}) => {
    await openHarness(page);

    // Seed two general notes through the UI.
    await page.getByRole('button', { name: 'Review' }).click();
    await addGeneralNote(page, 'First note', '1');
    await addGeneralNote(page, 'Second note', '2');

    await page.getByRole('button', { name: /Show .* comments/ }).click();

    const row = page.locator('#lp-list .lp-item').first();
    // Tag the row node so we can prove it is not rebuilt while arming/cancelling.
    await row.evaluate((el: HTMLElement) => {
        el.dataset.tag = 'orig';
    });

    // Arming delete slides a confirm overlay over the row (not an inline swap).
    await row.locator('.lp-del').click();
    const confirm = row.locator('.lp-item-confirm');
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText('Delete this comment?');
    expect(await row.evaluate((el: HTMLElement) => el.dataset.tag)).toBe(
        'orig',
    );

    // Cancel removes the overlay (after sliding out) and deletes nothing.
    await confirm.getByRole('button', { name: 'Cancel' }).click();
    await expect(row.locator('.lp-item-confirm')).toHaveCount(0);
    expect(await row.evaluate((el: HTMLElement) => el.dataset.tag)).toBe(
        'orig',
    );
    await expect(page.locator('#lp-head-count')).toHaveText('2');

    // Confirming actually deletes the comment (a DELETE to the server; the
    // count only drops once it lands).
    await row.locator('.lp-del').click();
    await row
        .locator('.lp-item-confirm')
        .getByRole('button', { name: 'Delete' })
        .click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
});

test('re-executing the script does not stack a second widget', async ({
    page,
}) => {
    await openHarness(page);
    await expect(page.locator('#lp-launcher')).toHaveCount(1);

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
    await expect(page.locator('#lp-launcher')).toHaveCount(1);
});

test("the 't' shortcut still works immediately after saving a comment", async ({
    page,
}) => {
    await openHarness(page);

    // Pick an element, type a comment, and save it.
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page.getByPlaceholder(/Describe the issue/).fill('Make this bigger');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');

    // Regression: saving left focus trapped in the now-hidden textarea, so isTyping()
    // reported true and the single-key shortcuts were suppressed. Pressing 't' with no
    // intervening click that would steal focus must still enter pick mode.
    await page.keyboard.press('t');
    await expect(page.locator('#lp-panel')).toBeHidden();
    await expect(page.locator('#lp-toast')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#lp-panel')).toBeVisible();
});

test('editing an anchored comment updates its body in place', async ({
    page,
}) => {
    await openHarness(page);

    // Seed an *anchored* comment through the UI so editing exercises the element
    // branch — the composeTarget rebuilt from the stored selector/text, not just
    // the general path.
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page.getByPlaceholder(/Describe the issue/).fill('Original note');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');

    await page.getByRole('button', { name: /Show .* comment/ }).click();

    const row = page.locator('#lp-list .lp-item').first();
    await row.locator('.lp-edit').click();

    // The composer reopens pre-filled with the stored body and keeps the element chip
    // (selector/text are rebuilt from the stored comment, so the anchor label shows).
    const textarea = page.getByPlaceholder(/Describe the issue/);
    await expect(textarea).toHaveValue('Original note');
    await expect(page.locator('#lp-compose-head')).toContainText(
        'A button to comment on',
    );

    await textarea.fill('Edited note');
    await page.getByRole('button', { name: 'Save' }).click();

    // The row shows the new body, no comment was added (still one), and the anchor (pin)
    // is preserved — editing changes the body only.
    await expect(page.locator('#lp-list .lp-item').first()).toContainText(
        'Edited note',
    );
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    await expect(page.locator('.pin')).toHaveText('1');

    // The PATCH landed server-side: the in-progress review holds the edited body.
    // (Asserted via the API — a harness reload would purge the draft.)
    const persisted = await fetchReviewComments(page);
    expect(persisted.map((comment) => comment.body)).toEqual(['Edited note']);
});

test('the pick-mode toast dodges away from the top edge', async ({ page }) => {
    await openHarness(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    const toast = page.locator('#lp-toast');
    await expect(toast).toBeVisible();

    const viewport = page.viewportSize()!;
    // The toast starts docked at the top.
    expect((await toast.boundingBox())!.y).toBeLessThan(viewport.height / 2);

    // Moving the cursor into the top band makes the toast slide to the bottom half so it
    // no longer covers the element being picked. The slide is animated — poll geometry.
    await page.mouse.move(60, 20);
    await expect
        .poll(async () => (await toast.boundingBox())!.y)
        .toBeGreaterThan(viewport.height / 2);

    // Moving back out of the band restores it to the top.
    await page.mouse.move(60, viewport.height / 2);
    await expect
        .poll(async () => (await toast.boundingBox())!.y)
        .toBeLessThan(viewport.height / 2);
});

test('the launcher exposes icon-only quick actions for note and pick', async ({
    page,
}) => {
    await openHarness(page);

    const launcher = page.locator('#lp-launcher');

    // "Add note" on the launcher opens the panel straight into the general composer,
    // without first having to open the panel and click the in-panel action.
    await launcher.getByRole('button', { name: 'Add note' }).click();
    await expect(page.locator('#lp-panel')).toBeVisible();
    await expect(page.getByPlaceholder(/Describe the issue/)).toBeVisible();
    await expect(page.locator('#lp-compose-head')).toContainText(
        'General comment',
    );

    // With the panel open the launcher's quick actions are hidden (they duplicate the
    // in-panel ones); only the Review toggle remains.
    await expect(
        launcher.getByRole('button', { name: 'Add note' }),
    ).toBeHidden();
    await expect(
        launcher.getByRole('button', { name: 'Pick element' }),
    ).toBeHidden();

    // Closing the panel brings the quick actions back.
    await page.getByRole('button', { name: 'Review' }).click();
    await expect(page.locator('#lp-panel')).toBeHidden();
    await expect(
        launcher.getByRole('button', { name: 'Pick element' }),
    ).toBeVisible();

    // "Pick element" on the launcher enters pick mode directly: the widget chrome hides
    // and the pick toast shows.
    await launcher.getByRole('button', { name: 'Pick element' }).click();
    await expect(page.locator('#lp-toast')).toBeVisible();
    await expect(page.locator('#lp-panel')).toBeHidden();
    await expect(launcher).toBeHidden();
});
