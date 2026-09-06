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
import { coverageScaled } from '../timeouts';

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
): Promise<
    Array<{
        body: string;
        selector: string;
        anchors: Array<{ selector: string; text: string }>;
    }>
> =>
    page.evaluate(async () => {
        const script = document.querySelector(
            'script[src*="site-review/widget.js"]',
        )!;
        const token = script.getAttribute('data-token')!;
        const response = await fetch('/api/site-review/review', {
            headers: { Authorization: `Bearer ${token}` },
        });
        const { comments } = (await response.json()) as {
            comments: Array<{
                body: string;
                selector: string;
                anchors: Array<{ selector: string; text: string }>;
            }>;
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
    await expect(saved).toContainText('Comment saved');

    const persisted = await fetchReviewComments(page);
    expect(persisted.map((comment) => comment.body)).toEqual([
        'Straight through',
    ]);

    // It is a toast, not a screen: it clears itself and the panel stays usable.
    await expect(saved).toBeHidden({ timeout: coverageScaled(10000) });
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
    await expect(panel.getByText(/apply that change/i)).toBeHidden();
});

test('a comment the agent already addressed can no longer be edited', async ({
    page,
}) => {
    await openHarness(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await addGeneralNote(page, 'Agent will take this', '1');

    // The server freezes a comment once it leaves Pending, which the API reports
    // as a 404 on PATCH. The widget must say so plainly rather than offering a
    // retry that would fail the same way. The 404 also makes it reconcile, so
    // the frozen row drops out of the list.
    await page.route('**/api/site-review/comments/*', (route) => {
        void route.fulfill({
            status: 404,
            contentType: 'application/json',
            body: JSON.stringify({ error: 'not_found' }),
        });
    });
    await page.route('**/api/site-review/review', (route) => {
        void route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ comments: [] }),
        });
    });

    await page.getByRole('button', { name: /Show .* comment/ }).click();
    await page.locator('#lp-list .lp-item').first().locator('.lp-edit').click();
    await page.getByPlaceholder(/Describe the issue/).fill('Too late');
    const panel = page.locator('#lp-panel');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(
        panel.getByText(/already picked that comment up/i),
    ).toBeVisible();
    await expect(page.locator('#lp-head-count')).toBeHidden();

    // Pressing Save again must repeat the refusal. The reconcile already dropped
    // the row, so this is the path where nothing is sent at all — and it is
    // exactly where a blanket "saved" toast would lie.
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(
        panel.getByText(/already picked that comment up/i),
    ).toBeVisible();
    await expect(page.locator('#lp-saved')).toBeHidden();
    await expect(page.getByPlaceholder(/Describe the issue/)).toHaveValue(
        'Too late',
    );
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

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test('the widget stays out of the way', async ({ page }) => {
        await registerUser(page);
        await page.goto(HARNESS_URL);

        await expect(page.locator('#lp-launcher')).toBeHidden();
        await expect(page.getByRole('button', { name: 'Review' })).toBeHidden();
    });
});

// Width alone would miss a phone on an embedder page with no viewport meta —
// those report a ~980px layout viewport — so a touch-primary device hides the
// widget at any width.
test.describe('on a touch device', () => {
    test.use({
        viewport: { width: 1280, height: 800 },
        hasTouch: true,
        isMobile: true,
    });

    test('the widget stays out of the way however wide the viewport', async ({
        page,
    }) => {
        await registerUser(page);
        await page.goto(HARNESS_URL);

        await expect(page.locator('#lp-launcher')).toBeHidden();
    });
});

// Pick mode's handlers and its crosshair live on the document, not in the
// shadow roots the breakpoint hides — so narrowing mid-pick would otherwise
// leave an invisible widget swallowing the page's clicks.
test('narrowing to a phone mid-pick stands the widget down', async ({
    page,
}) => {
    await registerUser(page);
    await page.goto(HARNESS_URL);

    await page
        .getByRole('button', { name: 'Pick element', exact: true })
        .click();
    await expect(page.locator('#lp-toast')).toBeVisible();
    await expect(page.locator('body')).toHaveCSS('cursor', 'crosshair');

    await page.setViewportSize({ width: 390, height: 844 });

    await expect(page.locator('body')).not.toHaveCSS('cursor', 'crosshair');

    // The launcher is gone at this width but the shortcuts are not: 't' must
    // not re-enter picking, and 'c' must not open a composer nobody can see.
    await page.keyboard.press('t');
    await expect(page.locator('body')).not.toHaveCSS('cursor', 'crosshair');
    await page.keyboard.press('c');
    await expect(page.locator('#lp-textarea')).toBeHidden();

    // Widening brings it back as it should be, not mid-pick.
    await page.setViewportSize({ width: 1280, height: 844 });
    await expect(page.locator('#lp-toast')).toBeHidden();
    await expect(page.getByRole('button', { name: 'Review' })).toBeVisible();
});

const keepHarnessUrl = `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}&keep=1`;

/**
 * Adding an anchor takes ⌘ on a Mac and Ctrl elsewhere, because Ctrl+click is a
 * right click on a Mac. Ask the browser rather than assume the runner's platform.
 */
const addAnchorKey = (page: Page): Promise<'Meta' | 'Control'> =>
    page.evaluate(() => {
        const nav = navigator as Navigator & {
            userAgentData?: { platform?: string };
        };
        const platform = `${nav.platform ?? ''} ${nav.userAgentData?.platform ?? ''}`;
        return /mac|iphone|ipad|ipod/i.test(platform) ? 'Meta' : 'Control';
    });

/**
 * Pick two elements into one composer. Pins only render when the comment's
 * stored url matches location.href, so the caller must already be on keepUrl.
 */
const addTwoElementComment = async (
    page: Page,
    body: string,
): Promise<void> => {
    const modifier = await addAnchorKey(page);
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page.keyboard.down(modifier);
    await page.locator('#target-two').click();
    await page.keyboard.up(modifier);
    await page.getByPlaceholder(/Describe the issue/).fill(body);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
};

/**
 * A comment can point at several elements, so it can say something about the
 * relationship between them. Each anchor gets its own pin, and every pin of one
 * comment carries that comment's number.
 */
test('a comment can be anchored to several elements at once', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);

    // Capture what the widget actually posts, not just what comes back.
    let saved: {
        selector: string;
        text: string;
        anchors: Array<{ selector: string; text: string }>;
    } | null = null;
    page.on('request', (request) => {
        if (
            request.method() === 'POST' &&
            request.url().includes('/api/site-review/comments')
        ) {
            saved = JSON.parse(request.postData() ?? '{}');
        }
    });

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();

    // The composer holds one anchor chip and says how to add another.
    const modifier = await addAnchorKey(page);
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        1,
    );
    await expect(page.locator('#lp-compose-head .lp-compose-hint')).toHaveText(
        /Hold .* to add another/,
    );

    // Holding the modifier brings the picker back, and the composer stays up so
    // the draft keeps its focus and the chips keep up with the picks.
    await page.keyboard.down(modifier);
    await expect(page.locator('#lp-toast')).toContainText(
        'Click to add another element',
    );
    await expect(page.locator('#lp-panel')).toBeVisible();
    await page.locator('#target-two').click();
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        2,
    );

    // Releasing it returns the normal composer.
    await page.keyboard.up(modifier);
    await expect(page.locator('#lp-toast')).toBeHidden();

    await page
        .getByPlaceholder(/Describe the issue/)
        .fill('These two should sit side by side');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');

    // One comment, two pins, both numbered 1 — that is what shows they belong
    // to the same comment.
    await expect(page.locator('.pin')).toHaveCount(2);
    await expect(page.locator('.pin').nth(0)).toHaveText('1');
    await expect(page.locator('.pin').nth(1)).toHaveText('1');

    // The API stored both anchors on one comment, and exactly two.
    const comments = await fetchReviewComments(page);
    expect(comments).toHaveLength(1);
    expect(comments[0].anchors).toHaveLength(2);

    // The POST repeats the first anchor in the scalar pair, so an instance that
    // predates anchors[] still records the element rather than saving a page
    // note. The current API prefers anchors[], hence the two above and not three.
    expect(saved).not.toBeNull();
    expect(saved.anchors).toHaveLength(2);
    expect(saved.selector).toBe(saved.anchors[0].selector);
    expect(saved.text).toBe(saved.anchors[0].text);

    // The list row names the count rather than one element's text.
    await page.getByRole('button', { name: /Show .* comment/ }).click();
    await expect(page.locator('#lp-list .lp-chip')).toHaveText('2 elements');

    // An edit PATCHes the body alone, so the composer offers no control that
    // would change the anchors and then silently discard the change.
    await page.locator('#lp-list .lp-edit').first().click();
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        2,
    );
    await expect(page.locator('#lp-compose-head .lp-chip-x')).toHaveCount(0);
    await expect(page.locator('#lp-compose-head .lp-compose-hint')).toHaveCount(
        0,
    );
    // The anchors are still outlined while editing, and none offers to remove
    // itself: a PATCH sends the body alone, so the change would be discarded.
    await expect(
        page.locator('#lp-hls > div:visible, #lp-hl:visible'),
    ).toHaveCount(2);
    await expect(page.locator('.highlight.removable')).toHaveCount(0);
    await expect(page.locator('#lp-hlx .lp-hl-x:visible')).toHaveCount(0);
    await page.keyboard.down(modifier);
    await expect(page.locator('#lp-toast')).toBeHidden();
    await page.keyboard.up(modifier);
});

/**
 * The × on a chip drops that element before the comment is saved, so a
 * mis-click during picking does not force the reviewer to start again.
 */
test('an element can be dropped from the composer before saving', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    const modifier = await addAnchorKey(page);
    await page.keyboard.down(modifier);
    await page.locator('#target-two').click();
    await page.keyboard.up(modifier);
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        2,
    );

    // Drop the second element again.
    await page.locator('#lp-compose-head .lp-chip-x').nth(1).click();
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        1,
    );

    await page
        .getByPlaceholder(/Describe the issue/)
        .fill('Only the first one');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');

    // One anchor was stored, and it is the element that was kept.
    const comments = await fetchReviewComments(page);
    expect(comments).toHaveLength(1);
    expect(comments[0].anchors).toHaveLength(1);
    await expect(page.locator('.pin')).toHaveCount(1);
});

/**
 * With four pills a reviewer cannot tell which names which element. Pointing at
 * one emphasises its element on the page. The rest stay painted, because a
 * comment is about the whole set and hiding them would flicker the page.
 */
test('pointing at a pill emphasises the anchor it names', async ({ page }) => {
    await openHarness(page);
    const modifier = await addAnchorKey(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page.keyboard.down(modifier);
    await page.locator('#target-two').click();
    await page.locator('#target-three').click();
    await page.locator('#target-input').click();
    await page.keyboard.up(modifier);
    // The release makes every anchor box hit-testable again, and the pointer
    // still rests on the element it just clicked. The widget then emphasises
    // that anchor, correctly. Park the pointer before asserting a resting state.
    await page.mouse.move(2, 2);
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        4,
    );

    // Each pill is a control with a name of its own, not a span with a listener.
    await expect(
        page.getByRole('button', {
            name: 'Highlight A second button to comment on',
        }),
    ).toBeVisible();

    const painted = page.locator('#lp-hl:visible, #lp-hls > div:visible');
    await expect(painted).toHaveCount(4);
    await expect(page.locator('.highlight.lit')).toHaveCount(0);

    // Hovering one pill emphasises one element and paints all four.
    await page.locator('.lp-chip-name[data-anchor-pill="1"]').hover();
    await expect(
        page.locator('.highlight.lit[data-anchor-hover="1"]'),
    ).toHaveCount(1);
    await expect(page.locator('.highlight.lit')).toHaveCount(1);
    await expect(painted).toHaveCount(4);
    await expect(
        page.locator('.lp-compose-chip[data-anchor-chip="1"]'),
    ).toHaveClass(/lit/);

    // Reaching for the pill's own × must not drop the emphasis. That control
    // removes the anchor, so this is the moment the reviewer most needs to see
    // which element it is. Assert the same anchor, not merely that one is lit.
    await page.locator('.lp-chip-x[data-anchor-remove="1"]').hover();
    await expect(
        page.locator('.highlight.lit[data-anchor-hover="1"]'),
    ).toHaveCount(1);
    await expect(page.locator('.highlight.lit')).toHaveCount(1);
    await expect(painted).toHaveCount(4);

    // Keyboard focus does the same. Two tabs move from one pill, past its own
    // remove control, to the next pill.
    await page.mouse.move(2, 2);
    await expect(page.locator('.highlight.lit')).toHaveCount(0);
    await page.locator('.lp-chip-name[data-anchor-pill="1"]').focus();
    await expect(
        page.locator('.highlight.lit[data-anchor-hover="1"]'),
    ).toHaveCount(1);

    // The first tab lands on this pill's own ×, still inside the same pill. A
    // count of the class transitions, because focusout and focusin net out: the
    // emphasis can blink off and back on between two states that both look right.
    await page.evaluate(() => {
        const overlay = [...document.querySelectorAll('*')]
            .map((node) => (node as HTMLElement).shadowRoot)
            .find((root) => root?.getElementById('lp-ov'));
        const counter = window as unknown as { __unlit: number };
        counter.__unlit = 0;
        const observer = new MutationObserver((records) => {
            for (const record of records) {
                const box = record.target as HTMLElement;
                if (
                    '1' === box.dataset.anchorHover &&
                    !box.classList.contains('lit')
                ) {
                    counter.__unlit += 1;
                }
            }
        });
        overlay!.querySelectorAll('.highlight').forEach((box) =>
            observer.observe(box, {
                attributes: true,
                attributeFilter: ['class'],
            }),
        );
    });
    await page.keyboard.press('Tab');
    await expect(
        page.locator('.lp-chip-x[data-anchor-remove="1"]'),
    ).toBeFocused();
    await expect(
        page.locator('.highlight.lit[data-anchor-hover="1"]'),
    ).toHaveCount(1);
    await expect(page.locator('.highlight.lit')).toHaveCount(1);
    expect(
        await page.evaluate(
            () => (window as unknown as { __unlit: number }).__unlit,
        ),
    ).toBe(0);

    await page.keyboard.press('Tab');
    await expect(
        page.locator('.lp-chip-name[data-anchor-pill="2"]'),
    ).toBeFocused();
    await expect(
        page.locator('.highlight.lit[data-anchor-hover="2"]'),
    ).toHaveCount(1);
    await expect(page.locator('.highlight.lit')).toHaveCount(1);
    await expect(painted).toHaveCount(4);

    // A keyboard user sees a ring on the pill. A mouse user does not, because a
    // press on a pill never focuses it.
    expect(
        await page
            .locator('.lp-chip-name[data-anchor-pill="2"]')
            .evaluate((el) => el.matches(':focus-visible')),
    ).toBe(true);
    await page.getByPlaceholder(/Describe the issue/).click();
    await page.locator('.lp-chip-name[data-anchor-pill="0"]').click();
    await expect(
        page.locator('.lp-chip-name[data-anchor-pill="0"]'),
    ).not.toBeFocused();
    await expect(page.getByPlaceholder(/Describe the issue/)).toBeFocused();
});

/**
 * Picking selects an element. It must not operate it. The browser focuses what a
 * mousedown lands on, which put a focus ring on the host page's field and took
 * the caret out of the composer for a gesture that only meant to select.
 */
test('picking an element does not give it focus', async ({ page }) => {
    await openHarness(page);
    const modifier = await addAnchorKey(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();

    // Count the focus the page's own elements receive. Asserting the end state
    // would not discriminate: the composer takes focus straight after a pick, so
    // a page element that was focused and then lost it leaves no trace.
    await page.evaluate(() => {
        const seen = window as unknown as { __hostFocus: number };
        seen.__hostFocus = 0;
        ['target-input', 'target-link'].forEach((id) =>
            document
                .getElementById(id)!
                .addEventListener('focus', () => (seen.__hostFocus += 1)),
        );
    });

    // A text field is the sharpest case: focus would put the caret in the page.
    await page.locator('#target-input').click();
    await expect(page.locator('#target-input')).not.toBeFocused();
    await expect(page.getByPlaceholder(/Describe the issue/)).toBeFocused();
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        1,
    );

    // A link is the other one: it must neither focus nor navigate.
    const url = page.url();
    await page.keyboard.down(modifier);
    await page.locator('#target-link').click();
    await page.keyboard.up(modifier);
    await expect(page.locator('#target-link')).not.toBeFocused();
    await expect(page).toHaveURL(url);
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        2,
    );

    // Neither pick ever moved focus onto the page, not even for an instant.
    expect(
        await page.evaluate(
            () => (window as unknown as { __hostFocus: number }).__hostFocus,
        ),
    ).toBe(0);

    // The caret is in the draft, so the reviewer types or pastes straight away.
    await expect(page.getByPlaceholder(/Describe the issue/)).toBeFocused();
    await page.keyboard.type('Typed without clicking first');
    await expect(page.getByPlaceholder(/Describe the issue/)).toHaveValue(
        'Typed without clicking first',
    );
});

/**
 * The chip is not the only way out. Each anchor's own box on the page carries a
 * remove control, so the reviewer drops an element by clicking the thing they
 * are looking at. Dropping one renumbers the rest and leaves no gap.
 */
test('an anchor can be dropped from its own box on the page', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);
    const modifier = await addAnchorKey(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page.keyboard.down(modifier);
    await page.locator('#target-two').click();
    await page.locator('#target-three').click();
    await page.keyboard.up(modifier);
    // `fill()` focuses without moving the pointer, so it would still rest on
    // the last element clicked, and its own control would be revealed.
    await page.mouse.move(2, 2);
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        3,
    );
    await page
        .getByPlaceholder(/Describe the issue/)
        .fill('All three of these');

    // Every anchor is outlined, and every outline has a control of its own.
    await expect(page.locator('.highlight.removable')).toHaveCount(3);
    await expect(page.locator('#lp-hlx .lp-hl-x:visible')).toHaveCount(3);

    // The control is revealed on hover rather than drawn permanently.
    const middle = page.locator('.highlight.removable[data-anchor-hover="1"]');
    const middleX = page.locator('.lp-hl-x[data-anchor-remove="1"]');
    await expect(middleX).toHaveCSS('opacity', '0');
    await middle.hover();
    await expect(middleX).toHaveCSS('opacity', '1');

    // Focus reveals it too, so it is reachable without a pointer.
    const firstX = page.locator('.lp-hl-x[data-anchor-remove="0"]');
    await firstX.focus();
    await expect(firstX).toBeFocused();
    await expect(firstX).toHaveCSS('opacity', '1');

    // Dropping the middle anchor renumbers the rest. The press must not focus
    // the control, so count the focus it receives rather than reading the end
    // state, which the composer reclaims either way.
    await middleX.evaluate((el) => {
        const seen = window as unknown as { __xFocus: number };
        seen.__xFocus = 0;
        el.addEventListener('focus', () => (seen.__xFocus += 1));
    });
    await middle.hover();
    await middleX.click();
    expect(
        await page.evaluate(
            () => (window as unknown as { __xFocus: number }).__xFocus,
        ),
    ).toBe(0);
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        2,
    );
    await expect(page.locator('.highlight.removable')).toHaveCount(2);
    await expect(page.locator('.lp-hl-x[data-anchor-remove="0"]')).toHaveCount(
        1,
    );
    await expect(page.locator('.lp-hl-x[data-anchor-remove="1"]')).toHaveCount(
        1,
    );
    await expect(page.locator('.lp-hl-x[data-anchor-remove="2"]')).toHaveCount(
        0,
    );
    await expect(page.locator('#lp-compose-head')).not.toContainText(
        'A second button',
    );

    // A mouse press must not focus the control. Focus goes back to the draft,
    // which is where the reviewer works next, rather than to the document.
    await expect(page.getByPlaceholder(/Describe the issue/)).toBeFocused();

    // The draft is untouched, and the survivors save in their original order.
    await expect(page.getByPlaceholder(/Describe the issue/)).toHaveValue(
        'All three of these',
    );
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    await expect(page.locator('.pin')).toHaveCount(2);
    const comments = await fetchReviewComments(page);
    expect(comments[0].anchors).toHaveLength(2);
    expect(comments[0].anchors[0].text).toContain('A button to comment on');
    expect(comments[0].anchors[1].text).toContain(
        'A third button to comment on',
    );
});

/**
 * Dropping the last anchor leaves a page note rather than an empty composer.
 * This is the mirror of holding the modifier over a page note, which gives it an
 * anchor and keeps the draft, so the pair stays symmetric.
 */
test('dropping the last anchor leaves a page note with the draft intact', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();
    await page
        .getByPlaceholder(/Describe the issue/)
        .fill('Actually about the page');

    const box = page.locator('.highlight.removable');
    await expect(box).toHaveCount(1);

    // The control sits over the element it removes, so its click must not reach
    // it. A fall-through would land on the host page under the overlay.
    await page.evaluate(() => {
        const counted = window as unknown as { __anchorHits: number };
        counted.__anchorHits = 0;
        document
            .getElementById('target-me')!
            .addEventListener('click', () => (counted.__anchorHits += 1));
    });
    await box.hover();
    await page.locator('#lp-hlx .lp-hl-x').click();
    expect(
        await page.evaluate(
            () => (window as unknown as { __anchorHits: number }).__anchorHits,
        ),
    ).toBe(0);

    // The comment becomes a page note and keeps what was typed.
    await expect(page.locator('#lp-compose-head')).toContainText(
        'General comment',
    );
    await expect(page.locator('.highlight.removable')).toHaveCount(0);
    await expect(page.getByPlaceholder(/Describe the issue/)).toHaveValue(
        'Actually about the page',
    );

    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    await expect(page.locator('.pin')).toHaveCount(0);
    const comments = await fetchReviewComments(page);
    expect(comments[0].body).toBe('Actually about the page');
    expect(comments[0].anchors).toHaveLength(0);
});

/**
 * The composer has a fixed height and clips, so a header full of chips must
 * scroll. Before it did, ten long labels pushed Save out of the clipped box and
 * the reviewer could not save a valid comment at all.
 */
test('the composer keeps Save reachable at the anchor cap', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);

    // Eleven targets whose labels are long enough to wrap the chip header. The
    // eleventh is the one the cap refuses.
    await page.evaluate(() => {
        for (let i = 1; i <= 11; i++) {
            const button = document.createElement('button');
            button.id = `long-${i}`;
            button.type = 'button';
            button.textContent = `A deliberately long element label number ${i} that keeps going well past the chip width`;
            // Keep them clear of the panel, which stays up while anchors are added.
            button.style.cssText = 'display:block;width:320px;text-align:left';
            document.body.appendChild(button);
        }
    });

    const modifier = await addAnchorKey(page);
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#long-1').click();
    await page.keyboard.down(modifier);
    for (let i = 2; i <= 10; i++) {
        await page.locator(`#long-${i}`).click();
    }

    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        10,
    );
    // At the cap the widget stops offering another pick, and an eleventh says so
    // rather than doing nothing.
    await expect(page.locator('#lp-compose-head .lp-compose-hint')).toHaveCount(
        0,
    );
    await page.locator('#long-11').click();
    await expect(page.locator('#lp-error')).toContainText(
        'A comment can point at 10 elements at most.',
    );
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        10,
    );
    await page.keyboard.up(modifier);
    await page.locator('#lp-error-dismiss').click();

    // Holding the modifier at the cap says the same thing instead of picking.
    await page.keyboard.down(modifier);
    await expect(page.locator('#lp-error')).toContainText(
        'A comment can point at 10 elements at most.',
    );
    await expect(page.locator('#lp-toast')).toBeHidden();
    await page.keyboard.up(modifier);
    await page.locator('#lp-error-dismiss').click();

    // The header scrolls instead of growing.
    const overflows = await page
        .locator('#lp-compose-head')
        .evaluate((el) => el.scrollHeight > el.clientHeight);
    expect(overflows).toBe(true);

    // Save stays inside the composer's clipped box, so it can still be clicked.
    const composerBox = await page.locator('#lp-composer').boundingBox();
    const saveBox = await page
        .getByRole('button', { name: 'Save' })
        .boundingBox();
    expect(saveBox!.y + saveBox!.height).toBeLessThanOrEqual(
        composerBox!.y + composerBox!.height + 1,
    );

    // The real proof: the comment saves.
    await page
        .getByPlaceholder(/Describe the issue/)
        .fill('Ten anchors with long labels');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    await expect(page.locator('.pin')).toHaveCount(10);
});

/**
 * A multi-anchor comment whose anchors only partly resolve must say so. Showing
 * one pin as though the comment had always been about one element would
 * misstate what the reviewer said.
 */
test('a multi-anchor comment renders as degraded when an element is gone', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);
    await addTwoElementComment(page, 'Align these two headings');
    await expect(page.locator('.pin')).toHaveCount(2);

    // Load the page again without one of the two targets. A pin renders only
    // when the comment's stored url matches location.href, so put the URL back
    // to the one the comment was saved on; the widget's history hook re-resolves
    // the anchors against the page it is now looking at.
    await page.goto(`${keepHarnessUrl}&hide=target-two`);
    await expect(page.locator('#target-two')).toHaveCount(0);
    await page.evaluate(
        (url) => history.replaceState({}, '', url),
        keepHarnessUrl,
    );

    // The surviving anchor still gets its pin, and it is marked degraded.
    const pin = page.locator('.pin');
    await expect(pin).toHaveCount(1);
    await expect(pin).toHaveClass(/degraded/);

    // Hovering names how many elements the comment lost.
    await pin.hover();
    await expect(page.locator('.lp-pop-degraded')).toContainText(
        '1 no longer on this page',
    );

    // The list row says the same thing.
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('button', { name: /Show .* comment/ }).click();
    await expect(page.locator('#lp-list .lp-chip')).toHaveText(
        '1 of 2 elements',
    );
});

/**
 * The modifier also works when it goes down before the first pick. No composer
 * is open then, so no keydown can arm the mode: the click's own modifier flag
 * is what keeps the picker up.
 */
test('the add-anchor modifier can be held before the very first pick', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);
    const modifier = await addAnchorKey(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();

    await page.keyboard.down(modifier);
    await page.locator('#target-me').click();
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        1,
    );
    await expect(page.locator('#lp-toast')).toContainText(
        'Click to add another element',
    );
    await page.locator('#target-two').click();
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        2,
    );
    await page.keyboard.up(modifier);
    await expect(page.locator('#lp-toast')).toBeHidden();

    // No keyup arrives when the hold ends outside the page, so losing focus
    // stands the picker down too.
    await page.keyboard.down(modifier);
    await expect(page.locator('#lp-toast')).toBeVisible();
    await page.evaluate(() => window.dispatchEvent(new Event('blur')));
    await expect(page.locator('#lp-toast')).toBeHidden();
    await page.keyboard.up(modifier);

    await page.getByPlaceholder(/Describe the issue/).fill('Both of these');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    const comments = await fetchReviewComments(page);
    expect(comments[0].anchors).toHaveLength(2);
});

/**
 * The composer's own modifier shortcuts must survive. A second key spends the
 * hold, and the composer keeps the focus throughout, so the shortcut reaches
 * the textarea exactly as it did before add-anchor mode existed.
 */
test('a second key spends the add-anchor hold and leaves the shortcut alone', async ({
    page,
}) => {
    await openHarness(page);
    const modifier = await addAnchorKey(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Pick element' })
        .click();
    await page.locator('#target-me').click();

    const textarea = page.getByPlaceholder(/Describe the issue/);
    await textarea.fill('select all of this');
    await expect(textarea).toBeFocused();

    await page.keyboard.down(modifier);
    await expect(page.locator('#lp-toast')).toBeVisible();
    // The composer stays up, so the draft never loses focus mid-hold.
    await expect(textarea).toBeFocused();

    // Select-all still selects, which can only happen if the widget let the
    // shortcut through to the focused textarea.
    await page.keyboard.press('a');
    await expect(page.locator('#lp-toast')).toBeHidden();
    const selection = await textarea.evaluate((el) => [
        (el as HTMLTextAreaElement).selectionStart,
        (el as HTMLTextAreaElement).selectionEnd,
    ]);
    expect(selection).toEqual([0, 'select all of this'.length]);

    // The hold stays spent until the modifier comes back up.
    await page.keyboard.press('a');
    await expect(page.locator('#lp-toast')).toBeHidden();
    await page.keyboard.up(modifier);
    await page.keyboard.down(modifier);
    await expect(page.locator('#lp-toast')).toBeVisible();
    await page.keyboard.up(modifier);
});

/**
 * A reviewer who starts a page note and then realises it is about one element
 * should not have to discard the draft. The hold points the note at an element
 * and keeps what is typed, and the composer says so rather than changing the
 * comment's type in silence.
 */
test('holding the modifier over a page note points it at an element', async ({
    page,
}) => {
    await openHarness(page);
    const modifier = await addAnchorKey(page);

    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await expect(page.locator('#lp-compose-head .lp-compose-hint')).toHaveText(
        /Hold .* to point at an element/,
    );
    await page.getByPlaceholder(/Describe the issue/).fill('Actually this bit');

    await page.keyboard.down(modifier);
    await page.locator('#target-me').click();
    await page.keyboard.up(modifier);

    // The draft survives and the note is now anchored.
    await expect(page.getByPlaceholder(/Describe the issue/)).toHaveValue(
        'Actually this bit',
    );
    await expect(page.locator('#lp-compose-head .lp-compose-chip')).toHaveCount(
        1,
    );

    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    const comments = await fetchReviewComments(page);
    expect(comments[0].body).toBe('Actually this bit');
    expect(comments[0].anchors).toHaveLength(1);
});

/**
 * A comment about several elements only reads as one comment if the reviewer
 * can see the whole set at once, so hovering any one anchor outlines them all.
 */
test('hovering one anchor outlines every anchor of the same comment', async ({
    page,
}) => {
    await openHarness(page);
    await page.goto(keepHarnessUrl);
    await addTwoElementComment(page, 'These two must agree');
    await expect(page.locator('.pin')).toHaveCount(2);

    const framed = page.locator('#lp-hl');
    const siblings = page.locator('#lp-hls > div:visible');
    await expect(framed).toBeHidden();
    await expect(siblings).toHaveCount(0);

    // Hovering the first pin frames its own element and outlines the second.
    await page.locator('.pin').first().hover();
    await expect(framed).toBeVisible();
    await expect(siblings).toHaveCount(1);
    const siblingBox = await siblings.boundingBox();
    const secondBox = await page.locator('#target-two').boundingBox();
    expect(Math.abs(siblingBox!.x - (secondBox!.x - 8))).toBeLessThan(2);
    expect(Math.abs(siblingBox!.y - (secondBox!.y - 8))).toBeLessThan(2);

    // Hovering the second pin swaps which one is framed, and still outlines both.
    await page.locator('.pin').nth(1).hover();
    await expect(framed).toBeVisible();
    await expect(siblings).toHaveCount(1);
    const swappedBox = await siblings.boundingBox();
    const firstBox = await page.locator('#target-me').boundingBox();
    expect(Math.abs(swappedBox!.x - (firstBox!.x - 8))).toBeLessThan(2);

    // Moving away clears every outline.
    await page.mouse.move(2, 2);
    await expect(framed).toBeHidden();
    await expect(siblings).toHaveCount(0);
});
