/**
 * End-to-end tests for the server-backed site-review annotation widget.
 *
 * The dev-only harness page (/dev/site-review-harness) finds-or-creates the
 * `e2e-harness` site for the user, deletes its comments and mints a fresh
 * site-bound token on every load — so each test starts from a clean site
 * simply by loading the harness (no localStorage involved).
 *
 * There is no send step. Every saved comment POSTs to
 * /api/site-review/comments and is Pending — live for the agent — from that
 * moment; the list rehydrates from GET /api/site-review/review on load, edits
 * PATCH and deletes DELETE. Only a Pending comment is still editable, so once
 * the agent addresses one the widget's PATCH/DELETE 404s by design.
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
const E2E_PASSWORD = 'E2eSiteReview1!';

const HARNESS_URL = `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}`;

/**
 * Register the e2e user (idempotent — re-registering is handled by the dev endpoint)
 * without loading the harness. Tests that need to intercept the widget's boot request
 * install their route between this and their own `page.goto(HARNESS_URL)`.
 */
const registerUser = async (page: Page): Promise<void> => {
    await suppressToolbar(page);
    const registerResponse = await page.request.post(
        '/dev/register-and-verify',
        {
            form: {
                fullName: 'E2E Site Review',
                email: E2E_EMAIL,
                password: E2E_PASSWORD,
            },
        },
    );
    expect(registerResponse.status()).toBe(200);
};

/**
 * Seed the user and load the harness. Every load clears the site's comments.
 */
const openHarness = async (page: Page): Promise<void> => {
    await registerUser(page);
    await page.goto(HARNESS_URL);
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
 * Read the site's live comments straight from the API using the widget's own
 * token (from the script tag). This proves server persistence without
 * reloading — a harness reload deliberately purges them, so "survives reload"
 * cannot be asserted against the harness.
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
        const { comments } = (await response.json()) as {
            comments: Array<{ body: string; selector: string }>;
        };
        return comments;
    });

test('annotate a page and have every comment go live as it is saved', async ({
    page,
}) => {
    await openHarness(page);

    // The collapsed launcher carries no count badge when empty (the harness
    // cleared the site, so the boot rehydrate finds nothing).
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

    // Both comments are live on the server the moment they were saved, with no
    // send step in between. Read them back through the widget's own token.
    const persisted = await fetchReviewComments(page);
    expect(persisted.map((comment) => comment.body).sort()).toEqual([
        'A general note about the page',
        'Make this bigger',
    ]);

    // Nothing is left to submit, so there is no Send button to press.
    await expect(page.getByRole('button', { name: 'Send' })).toHaveCount(0);
});

test('saving a comment confirms it is live', async ({ page }) => {
    await openHarness(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await page.getByPlaceholder(/Describe the issue/).fill('Straight through');

    // Dropping the "review sent" screen would leave the reviewer with nothing
    // telling them the comment persisted, so the save raises a brief toast.
    const saved = page.locator('#lp-saved');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(saved).toBeVisible();
    await expect(saved).toContainText('your agent can see it now');

    const persisted = await fetchReviewComments(page);
    expect(persisted.map((comment) => comment.body)).toEqual([
        'Straight through',
    ]);

    // It is a toast, not a screen: it clears itself and the panel stays usable.
    await expect(saved).toBeHidden({ timeout: 10000 });
    await expect(page.locator('#lp-main')).toBeVisible();
});

test('a keep=1 reload rehydrates the live comments into pins and list', async ({
    page,
}) => {
    // First load purges any leftover comments; then move to the keep=1 URL and do
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

    // Reload the harness with keep=1: the comments survive (only the token is
    // re-minted; they belong to the site, not the token) and the widget
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

test('a failed save keeps the text in the composer so it can be retried', async ({
    page,
}) => {
    await openHarness(page);

    // Seed one comment through the UI (it POSTs to the server immediately).
    await page.getByRole('button', { name: 'Review' }).click();
    await addGeneralNote(page, 'A general note about the page', '1');

    // Make the backend reject the next save.
    let calls = 0;
    await page.route('**/api/site-review/comments', (route) => {
        calls += 1;
        void route.fulfill({
            status: 500,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'boom' }),
        });
    });

    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    const textarea = page.getByPlaceholder(/Describe the issue/);
    await textarea.fill('This one will not land');
    await page.getByRole('button', { name: 'Save' }).click();

    // The banner appears, and — critically — the composer stays open with the
    // text intact, so pressing Save again *is* the retry.
    const panel = page.locator('#lp-panel');
    await expect(panel.getByText(/apply that change/i)).toBeVisible();
    await expect(textarea).toHaveValue('This one will not land');
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    await expect.poll(() => calls).toBe(1);

    // Save again re-fires the same POST; the earlier comment is untouched.
    await page.getByRole('button', { name: 'Save' }).click();
    await expect.poll(() => calls).toBe(2);
    await expect(page.locator('#lp-head-count')).toHaveText('1');

    // Dismiss clears the banner without touching anything else.
    await panel.getByRole('button', { name: 'Dismiss' }).click();
    await expect(panel.getByText(/apply that change/i)).toHaveCount(0);
});

test('a comment the agent already addressed can no longer be edited', async ({
    page,
}) => {
    await openHarness(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await addGeneralNote(page, 'Agent will take this', '1');

    // The server freezes a comment once it leaves Pending, which the API reports
    // as a 404 on PATCH. The widget must say so plainly rather than offering a
    // retry that would fail the same way.
    await page.route('**/api/site-review/comments/*', (route) => {
        void route.fulfill({
            status: 404,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'not_found' }),
        });
    });

    await page.getByRole('button', { name: /Show .* comment/ }).click();
    await page.locator('#lp-list .lp-item').first().locator('.lp-edit').click();
    await page.getByPlaceholder(/Describe the issue/).fill('Too late');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(
        page.locator('#lp-panel').getByText(/already picked that comment up/i),
    ).toBeVisible();
});

test('a 403 on the boot load drops the widget into a critical, dead-end state', async ({
    page,
}) => {
    await registerUser(page);

    // The very first call the widget makes on boot — GET /review — 403s with the unbound
    // token code. Installing the route before navigating means the widget hits it on load,
    // so it must catch the rejection immediately.
    await page.route('**/api/site-review/review', (route) => {
        void route.fulfill({
            status: 403,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'token_not_bound_to_site' }),
        });
    });
    await page.goto(HARNESS_URL);

    // The collapsed launcher flags the problem with a danger badge before it is opened.
    await expect(page.locator('#lp-launch-alert')).toBeVisible();

    await page.getByRole('button', { name: 'Review' }).click();
    const panel = page.locator('#lp-panel');
    // The panel is replaced by the critical state, and the message is tailored to the
    // *unbound* code — "regenerate the widget token" — not a generic rejection.
    await expect(panel.getByText(/can.t connect/i)).toBeVisible();
    await expect(panel.getByText(/isn.t linked to a site/i)).toBeVisible();
    await expect(panel.getByText(/regenerate the widget token/i)).toBeVisible();

    // It is a dead end: the whole normal UI (composer + actions) is gone, and there is no
    // retry that would just 403 again.
    await expect(panel.locator('#lp-main')).toBeHidden();
    await expect(page.getByPlaceholder(/Describe the issue/)).toBeHidden();
    await expect(panel.getByRole('button', { name: 'Try again' })).toHaveCount(
        0,
    );
    await expect(panel.getByRole('button', { name: 'Dismiss' })).toHaveCount(0);
});

test('a 401 on the boot load reports an invalid / revoked token', async ({
    page,
}) => {
    await registerUser(page);

    await page.route('**/api/site-review/review', (route) => {
        void route.fulfill({
            status: 401,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'unauthorized' }),
        });
    });
    await page.goto(HARNESS_URL);

    await page.getByRole('button', { name: 'Review' }).click();
    const panel = page.locator('#lp-panel');
    await expect(panel.getByText(/can.t connect/i)).toBeVisible();
    await expect(panel.getByText(/invalid or was revoked/i)).toBeVisible();
});

test('a wrong-scope 403 tells the embedder to use the widget token', async ({
    page,
}) => {
    await registerUser(page);

    // A non-widget token (e.g. an MCP token) authenticates but lacks the site-review
    // scope: the firewall returns 403 with `insufficient_scope`. The message must point at
    // the token *type*, not tell them to regenerate the (correct) widget token.
    await page.route('**/api/site-review/review', (route) => {
        void route.fulfill({
            status: 403,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'insufficient_scope' }),
        });
    });
    await page.goto(HARNESS_URL);

    await page.getByRole('button', { name: 'Review' }).click();
    const panel = page.locator('#lp-panel');
    await expect(panel.getByText(/can.t connect/i)).toBeVisible();
    await expect(panel.getByText(/not another API token/i)).toBeVisible();
});

test('a token revoked mid-session goes fatal and clears the on-page pins', async ({
    page,
}) => {
    await openHarness(page);

    // Seed an anchored comment so a live pin is rendered on the page.
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page.getByPlaceholder(/Describe the issue/).fill('Anchored note');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.pin')).toHaveText('1');

    // The token is revoked between load and the next save: that POST 401s.
    await page.route('**/api/site-review/comments', (route) => {
        void route.fulfill({
            status: 401,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'unauthorized' }),
        });
    });
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await page.getByPlaceholder(/Describe the issue/).fill('Second note');
    await page.getByRole('button', { name: 'Save' }).click();

    // The widget flips to the critical state AND the stale pin is gone — no interactive
    // dead-end left on the page.
    const panel = page.locator('#lp-panel');
    await expect(panel.getByText(/can.t connect/i)).toBeVisible();
    await expect(page.locator('.pin')).toHaveCount(0);
});

test('a boot rejection landing after the user entered pick mode still surfaces fatal', async ({
    page,
}) => {
    await registerUser(page);

    // Hold the boot GET open until the test releases it, so we can deterministically enter
    // pick mode *before* the rejection lands (a real race between boot and user action).
    let releaseBoot = (): void => {};
    const bootGate = new Promise<void>((resolve) => {
        releaseBoot = resolve;
    });
    await page.route('**/api/site-review/review', async (route) => {
        await bootGate;
        await route.fulfill({
            status: 403,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'token_not_bound_to_site' }),
        });
    });
    await page.goto(HARNESS_URL);

    // Enter pick mode while the boot request is still in flight (scrim + toast up, widget
    // chrome hidden).
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await expect(page.locator('#lp-toast')).toBeVisible();

    // Release the boot 403: the widget must leave pick mode and surface the fatal panel,
    // not stay stuck behind the picker scrim.
    releaseBoot();
    await expect(page.locator('#lp-toast')).toBeHidden();
    await expect(
        page.locator('#lp-panel').getByText(/can.t connect/i),
    ).toBeVisible();
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

    // The PATCH landed server-side: the site holds the edited body.
    // (Asserted via the API — a harness reload would purge the comments.)
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
