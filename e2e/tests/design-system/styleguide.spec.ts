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

test('the catalog draws the button in each variant', async ({ page }) => {
    await page.goto('/styleguide');
    const button = page.locator('[data-component="Button"]');
    await expect(button).toBeVisible();
    await expect(page.locator('[data-catalog-empty]')).toHaveCount(0);
    await expect(
        button.locator('[data-variant="primary"] .lp-btn--primary').first(),
    ).toBeVisible();
    await expect(
        button.locator('[data-variant="danger"] .lp-btn--danger').first(),
    ).toBeVisible();
});

test('the catalog draws each form part', async ({ page }) => {
    await page.goto('/styleguide');
    const parts: Record<string, string> = {
        Input: '.lp-input',
        Select: '.lp-select',
        Textarea: '.lp-textarea',
        Label: '.lp-label',
        FormField: '.lp-form-field',
        FieldErrors: '.lp-field-errors',
        Hint: '.lp-form-hint',
    };
    for (const [name, selector] of Object.entries(parts)) {
        await expect(
            page.locator(`[data-component="${name}"] ${selector}`).first(),
        ).toBeVisible();
    }
});
