import { expect } from '@playwright/test';
import { createTest } from '../fixtures';
import { submitRedirectingForm } from '../helpers';
import { coverageScaled } from '../timeouts';

const test = createTest({
    email: 'e2e-token-revocation@example.com',
    password: 'e2e_password_123',
    name: 'Token reviewer',
});

test('the Data section holds both account data panels', async ({ page }) => {
    await page.goto('/account');
    await page
        .locator('.lp-settings-nav')
        .getByRole('link', { name: 'Data' })
        .click();
    await expect(page).toHaveURL('/account/data');
    await expect(
        page.locator('.lp-settings-nav [aria-current="page"]'),
    ).toHaveAttribute('href', '/account/data');
    await expect(page.getByTestId('export-section')).toBeVisible();
    await expect(page.getByTestId('delete-account-section')).toBeVisible();
    await expect(page.getByTestId('api-tokens-section')).toHaveCount(0);
});

test('revocation requires confirmation and disables the credential', async ({
    page,
    playwright,
}) => {
    await page.goto('/account/api-tokens');
    const label = `Revocation ${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
    const form = page.getByTestId('mint-api-token-form');
    await form.getByLabel('Name').fill(label);
    await page.route('**/account/api-tokens', async (route) => {
        if (route.request().method() !== 'POST') {
            return route.continue();
        }
        const response = await route.fetch({ maxRedirects: 0 });
        await new Promise((resolve) =>
            setTimeout(resolve, coverageScaled(6000)),
        );
        await route.fulfill({ response });
    });
    await submitRedirectingForm(
        page,
        form.getByRole('button', { name: 'Create token' }),
        '/account/api-tokens',
    );
    // The mint and its redirect share this path, so the delay must not
    // outlive the mint and slow every later load of the page.
    await page.unroute('**/account/api-tokens');
    const secret = page.getByTestId('minted-api-token-value');
    await expect(secret).toBeVisible();
    const raw = (await secret.innerText()).trim();
    const api = await playwright.request.newContext({
        baseURL: new URL(page.url()).origin,
        ignoreHTTPSErrors: true,
        extraHTTPHeaders: {
            Authorization: `Bearer ${raw}`,
            'X-Playwright': '1',
        },
    });

    try {
        expect((await api.get('/api/events')).status()).toBe(200);
        await page.goto('/account/api-tokens');
        await expect(secret).toHaveCount(0);
        const row = page.locator('[data-token-id]').filter({ hasText: label });
        const revoke = row.getByRole('button', { name: 'Revoke', exact: true });
        const dialog = page.getByRole('dialog', { name: 'Revoke this token?' });
        await revoke.click();
        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText(label);
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toBeHidden();
        await expect(revoke).toBeFocused();
        expect((await api.get('/api/events')).status()).toBe(200);

        await revoke.click();
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(revoke).toBeFocused();
        expect((await api.get('/api/events')).status()).toBe(200);

        await revoke.click();
        await dialog
            .getByRole('button', { name: 'Revoke token', exact: true })
            .click();
        await expect(page.locator('.lp-flash')).toContainText(
            `Token "${label}" has been revoked.`,
        );
        await expect(page).toHaveURL('/account/api-tokens');
        await expect(page.getByTestId('api-tokens-section')).toBeVisible();
        await expect(row).toHaveCount(0);
        expect((await api.get('/api/events')).status()).toBe(401);
        await page.reload();
        await expect(row).toHaveCount(0);
    } finally {
        await api.dispose();
    }
});
