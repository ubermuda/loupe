import { test as guestTest, expect, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const test = createTest({
    email: 'e2e-skip-link@example.com',
    password: 'E2eSkipLink1!',
});

test('skip link targets the new content after Turbo navigation', async ({
    page,
}) => {
    await page.goto('/projects');
    await page.locator('.lp-sidebar a[href="/account"]').click();
    await expect(page).toHaveURL('/account');
    const skip = page.getByRole('link', {
        name: 'Skip to content',
        exact: true,
    });
    await skip.focus();
    await page.screenshot({ path: '/tmp/loupe-skip-link-account.png' });
    await skip.press('Enter');
    await expect(page.getByRole('main')).toBeFocused();
    await expect(
        page
            .getByRole('main')
            .getByRole('heading', { name: 'Your account', exact: true }),
    ).toBeVisible();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('main').locator(':focus')).toHaveCount(1);
});

async function skipNavigation(page: Page): Promise<void> {
    const link = page.getByRole('link', {
        name: 'Skip to content',
        exact: true,
    });
    await page.keyboard.press('Tab');
    await expect(link).toBeFocused();
    await expect(link).toHaveCSS('position', 'fixed');
    const bounds = await link.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds!.x).toBeGreaterThanOrEqual(0);
    expect(bounds!.y).toBeGreaterThanOrEqual(0);
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(
        page.viewportSize()!.width,
    );
    await link.press('Enter');
    await expect(page.getByRole('main')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('main').locator(':focus')).toHaveCount(1);
}

for (const width of [1440, 1150, 950, 780, 390]) {
    for (const textSize of [100, 200]) {
        test(`app skip link at ${width}px and ${textSize}% text`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto('/projects');
            await page.addStyleTag({
                content: `html { font-size: ${textSize}%; }`,
            });
            await skipNavigation(page);
            await expect(
                page
                    .locator('.lp-page-header')
                    .getByRole('button', { name: 'New project', exact: true }),
            ).toBeFocused();
        });

        guestTest.describe(`guest ${width}px ${textSize}%`, () => {
            guestTest.use({ storageState: { cookies: [], origins: [] } });

            guestTest(
                `guest skip links at ${width}px and ${textSize}% text`,
                async ({ page }) => {
                    await suppressToolbar(page);
                    await suppressWidget(page);
                    await page.setViewportSize({ width, height: 1000 });
                    for (const path of ['/register', '/privacy']) {
                        await page.goto(path);
                        await page.addStyleTag({
                            content: `html { font-size: ${textSize}%; }`,
                        });
                        await skipNavigation(page);
                        await expect(page).toHaveURL(`${path}#main-content`);
                    }
                },
            );
        });
    }
}
