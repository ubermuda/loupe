import {
    test,
    expect,
    type APIRequestContext,
    type Locator,
    type Page,
} from '@playwright/test';

/**
 * The landing page lets a visitor review the landing page. It runs the review
 * screen's own comment-anchor controller with a transport that keeps nothing,
 * so the thing being demonstrated is the product rather than a lookalike — and
 * the claim that nothing is saved is what the reload assertion holds to.
 *
 * Below xl there is no gutter for a card, so the demo declines the selection.
 */
test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

const MARGIN = '[data-comment-anchor-target="margin"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';

async function setLandingFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'landing.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

test.beforeEach(async ({ request, page }) => {
    // Seeded off, so `/` redirects guests to /login until it is on.
    await setLandingFlag(request, true);
    await page.goto('/');
});

// The flag is global: left on, a later spec's signed-out user lands here
// instead of on /login, which is how delete-account started failing.
test.afterAll(async ({ request }) => {
    await setLandingFlag(request, false);
});

/** Drag across the first line of a paragraph, as a reader would. */
async function selectFirstLine(page: Page, paragraph: Locator): Promise<void> {
    await paragraph.scrollIntoViewIfNeeded();
    const box = await paragraph.boundingBox();
    expect(box).not.toBeNull();
    if (box === null) {
        return;
    }
    const y = box.y + 8;
    await page.mouse.move(box.x + 2, y);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width * 0.6, y, { steps: 12 });
    await page.mouse.up();
}

function firstProse(page: Page): Locator {
    return page.locator('.lp-demo-page .lp-landing-lead').first();
}

test('a visitor comments on the landing copy and the card lands beside it', async ({
    page,
}) => {
    await selectFirstLine(page, firstProse(page));
    await expect(page.locator(TOOLBAR)).toBeVisible();

    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill('This sentence is doing two jobs.');
    await page.getByRole('button', { name: 'Post', exact: true }).click();

    const card = page.locator(`${MARGIN} .lp-comment-thread`);
    await expect(card).toHaveCount(1);
    await expect(card).toContainText('This sentence is doing two jobs.');
    // Positioned against its passage rather than left in flow.
    await expect(card).toHaveAttribute('style', /top:\s*\d+px/);
});

test('a demo comment is gone on reload', async ({ page }) => {
    await selectFirstLine(page, firstProse(page));
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill('Nothing here is saved.');
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    await expect(page.locator(`${MARGIN} .lp-comment-thread`)).toHaveCount(1);

    await page.reload();
    await expect(page.locator(`${MARGIN} .lp-comment-thread`)).toHaveCount(0);
});

test('a struck passage needs no composer', async ({ page }) => {
    await selectFirstLine(page, firstProse(page));
    await page.getByRole('button', { name: /^Strike/ }).click();

    const card = page.locator(`${MARGIN} .lp-comment-thread`);
    await expect(card).toHaveCount(1);
    await expect(card).toHaveAttribute('data-anchor-kind', 'strike');
    await expect(card.locator('.lp-comment-body')).toHaveCount(0);
});

test('a card can be deleted', async ({ page }) => {
    await selectFirstLine(page, firstProse(page));
    await page.getByRole('button', { name: /^Strike/ }).click();
    const card = page.locator(`${MARGIN} .lp-comment-thread`);
    await expect(card).toHaveCount(1);

    await card.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(card).toHaveCount(0);
});

test('the widget on the landing page is the one backed by nothing', async ({
    page,
}) => {
    const widget = page.locator('script[src*="site-review/widget.js"]');
    await expect(widget).toHaveCount(1);
    await expect(widget).toHaveAttribute('data-demo', '');
});

test('the page says it can be annotated, and points at the widget', async ({
    page,
}) => {
    await expect(page.locator('.lp-demo-hint')).toBeVisible();
    await expect(page.locator('.lp-demo-widget-hint')).toBeVisible();
});

test('the widget call-out leaves the slot its panel opens into', async ({
    page,
}) => {
    const hint = page.locator('.lp-demo-widget-hint');
    await expect(hint).toBeVisible();

    await page.locator('#lp-launch-main').click();
    await expect(hint).toBeHidden();

    await page.locator('#lp-close').click();
    await expect(hint).toBeVisible();
});

test.describe('on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    // It points at the widget's launcher, which is itself gone at this width.
    test('the widget call-out goes with the widget', async ({ page }) => {
        await expect(page.locator('.lp-demo-widget-hint')).toBeHidden();
    });
});

test.describe('below xl', () => {
    test.use({ viewport: { width: 1024, height: 900 } });

    test('the demo declines the selection', async ({ page }) => {
        await selectFirstLine(page, firstProse(page));
        await expect(page.locator(TOOLBAR)).toBeHidden();
    });

    // The widget works at every width, so only the selection call-out goes.
    test('the call-out that invites a selection is gone', async ({ page }) => {
        await expect(page.locator('.lp-demo-hint')).toBeHidden();
        await expect(page.locator('.lp-demo-widget-hint')).toBeVisible();
    });
});

test('narrowing past the threshold drops a selection already captured', async ({
    page,
}) => {
    await selectFirstLine(page, firstProse(page));
    await expect(page.locator(TOOLBAR)).toBeVisible();

    await page.setViewportSize({ width: 1024, height: 900 });
    await expect(page.locator(TOOLBAR)).toBeHidden();

    // The shortcut reads the captured selection, not the toolbar, so a stale
    // capture would strike a passage with nowhere to show the result.
    await page.keyboard.press('s');
    await expect(page.locator(`${MARGIN} .lp-comment-thread`)).toHaveCount(0);
});
