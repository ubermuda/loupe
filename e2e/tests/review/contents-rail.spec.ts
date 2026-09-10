/**
 * Browser coverage for the contents rail, which only exists past 1600px.
 *
 * Two things nothing else can check. A heading link must scroll the pane
 * rather than navigate: it is an in-page `#hash`, and a Turbo visit would take
 * the reader back to the top a moment after they arrived. And the current row
 * must follow the reading position through a section taller than the pane,
 * where no heading is on screen at all.
 *
 * Every test drives its own user and document through the dev-only endpoints.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eContentsRail1!';

const FILLER = Array.from(
    { length: 24 },
    (_, index) =>
        `Paragraph ${index + 1} of a section deliberately taller than the pane, so that no heading of its own is on screen while the reader is inside it.`,
).join('\n\n');

const MARKDOWN = `## Alpha

Alpha is short.

## Beta

${FILLER}

## Gamma

Gamma is short too.`;

const RAIL = '.lp-review-rail';
const RAIL_LINK = `${RAIL} .lp-review-contents__link`;
const CURRENT = `${RAIL} .lp-review-contents__link--current`;

async function devRegisterAndVerify(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Rail Reviewer', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);
}

async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // A cold PHP cache makes the first sign-in of a run slow.
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

const test = base.extend<{ reviewUrl: string }>({
    reviewUrl: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+rail+${tag}+${RUN}@example.com`;
            await devRegisterAndVerify(page, email);
            await login(page, email);

            const response = await page.request.post('/dev/seed/document', {
                form: {
                    title: 'E2E Contents Rail Document',
                    markdown: MARKDOWN,
                },
            });
            expect(response.status()).toBe(201);
            const body = await response.json();
            const url = `/projects/${body.projectId}/documents/${body.documentId}/review`;

            await page.goto(url);
            await use(url);
        },
        { auto: true },
    ],
});

test.use({ storageState: { cookies: [], origins: [] } });
// The rail is hidden below 100rem, so every test here needs a wide window.
test.use({ viewport: { width: 1700, height: 900 } });
test.describe.configure({ timeout: 90000 });

const paneScrollTop = (page: Page): Promise<number> =>
    page.locator('.lp-main').evaluate((pane) => pane.scrollTop);

test('a heading in the rail scrolls the pane and never navigates', async ({
    page,
    reviewUrl,
}) => {
    await expect(page.locator(RAIL)).toBeVisible();
    expect(await paneScrollTop(page)).toBe(0);

    await page.locator(RAIL_LINK).filter({ hasText: 'Gamma' }).click();

    // The scroll is animated, so the assertion polls rather than reading once.
    await expect
        .poll(() => paneScrollTop(page), { timeout: 5000 })
        .toBeGreaterThan(200);
    // A Turbo visit would have put the heading's id in the address bar and
    // taken the reader back to the top.
    await expect(page).toHaveURL(reviewUrl);
});

test('the current row holds through a section taller than the pane', async ({
    page,
}) => {
    await expect(page.locator(RAIL)).toBeVisible();
    await expect(page.locator(CURRENT)).toHaveText(/Alpha/);

    await page.locator(RAIL_LINK).filter({ hasText: 'Beta' }).click();
    await expect(page.locator(CURRENT)).toHaveText(/Beta/);

    // Far enough into Beta that its own heading has left the pane, and short of
    // Gamma. The row must still say Beta.
    await page.locator('.lp-main').evaluate((pane) => {
        pane.scrollTop += 500;
    });
    await expect(page.locator(CURRENT)).toHaveText(/Beta/);

    await page.locator(RAIL_LINK).filter({ hasText: 'Gamma' }).click();
    await expect(page.locator(CURRENT)).toHaveText(/Gamma/);
});

/**
 * The last heading sits within a screen of the end, so it never reaches the
 * activation line however far the reader scrolls. Asking for it has to mark it
 * anyway, or the rail answers a click by marking a different row.
 */
test('the last heading becomes current even though it never reaches the line', async ({
    page,
}) => {
    await expect(page.locator(RAIL)).toBeVisible();

    const last = page.locator(RAIL_LINK).last();
    await expect(last).toHaveText(/Gamma/);
    await last.click();

    await expect(page.locator(CURRENT)).toHaveText(/Gamma/);
    // And it stays marked once the scroll has settled at the bottom.
    await expect
        .poll(
            () =>
                page
                    .locator('.lp-main')
                    .evaluate(
                        (pane) =>
                            pane.scrollTop >=
                            pane.scrollHeight - pane.clientHeight - 1,
                    ),
            { timeout: 5000 },
        )
        .toBe(true);
    await expect(page.locator(CURRENT)).toHaveText(/Gamma/);
});

test('arriving at a heading names it for a moment', async ({ page }) => {
    await expect(page.locator(RAIL)).toBeVisible();

    await page.locator(RAIL_LINK).filter({ hasText: 'Beta' }).click();

    const head = page
        .locator('.lp-section-head')
        .filter({ hasText: 'Beta' })
        .first();
    await expect(head).toHaveClass(/lp-section-head--arrived/);
    // It names the heading rather than marking it permanently.
    await expect(head).not.toHaveClass(/lp-section-head--arrived/, {
        timeout: 5000,
    });
});

test('the current row survives an approval, which replaces the rows', async ({
    page,
}) => {
    await expect(page.locator(RAIL)).toBeVisible();
    await expect(page.locator(CURRENT)).toHaveText(/Alpha/);

    await page
        .locator(
            '[data-comment-anchor-target="doc"] [data-section-approve="heading-alpha"]',
        )
        .click();
    // The rail count is streamed alongside the rows, so it says the swap landed.
    await expect(page.locator('#review-rail-sections-count')).toHaveText(
        '1/3',
        { timeout: 20000 },
    );

    await expect(page.locator(CURRENT)).toHaveText(/Alpha/);

    // And the rail still follows the reader afterwards: it marks the headings
    // of the document, which the stream never replaced.
    await page.locator('.lp-main').evaluate((pane) => {
        pane.scrollTop += 900;
    });
    await expect(page.locator(CURRENT)).toHaveText(/Beta/);
});
