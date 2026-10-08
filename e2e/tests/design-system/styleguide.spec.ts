import { test, expect } from '@playwright/test';

/**
 * The styleguide draws the tokens from the real stylesheet, so a token that
 * moves out of tokens.css, or a group that stops parsing, shows up here as a
 * missing section. It is a dev-only page and needs no account.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const GROUPS = ['colour', 'type', 'spacing', 'radius', 'shadow', 'motion'];

test('the styleguide shows every token group', async ({ page }) => {
    await page.goto('/styleguide');
    await expect(
        page.getByRole('heading', { name: 'Styleguide', level: 1 }),
    ).toBeVisible();

    for (const group of GROUPS) {
        const section = page.locator(`[data-token-group="${group}"]`);
        await expect(section).toBeVisible();
        expect(await section.locator('[data-token]').count()).toBeGreaterThan(
            0,
        );
    }
});

test('a colour swatch paints the accent token', async ({ page }) => {
    await page.goto('/styleguide');
    const swatch = page.locator(
        '[data-token="--accent"] .lp-styleguide__swatch',
    );
    await expect(swatch).toHaveCSS('background-color', 'rgb(212, 233, 76)');
});

test('the catalog says it has no entry yet', async ({ page }) => {
    await page.goto('/styleguide');
    await expect(page.locator('[data-catalog-empty]')).toBeVisible();
});
