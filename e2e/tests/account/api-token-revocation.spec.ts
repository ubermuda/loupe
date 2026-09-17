import { expect } from '@playwright/test';
import { createTest } from '../fixtures';
import { submitRedirectingForm } from '../helpers';

const test = createTest({
    email: 'e2e-token-revocation@example.com',
    password: 'e2e_password_123',
    name: 'Token reviewer',
});

test('the Data tab identifies both account data panels', async ({ page }) => {
    await page.goto('/account');
    const tab = page.locator('#account-tab-data');
    await expect(tab).toHaveAttribute('aria-controls', 'data danger-zone');
    await tab.click();
    await expect(page.locator('#data')).toBeVisible();
    await expect(page.locator('#danger-zone')).toBeVisible();
});

test('revocation requires confirmation and disables the credential', async ({
    page,
    playwright,
}) => {
    await page.goto('/account?tab=api-tokens');
    const label = `Revocation ${Date.now()}`;
    const form = page.getByTestId('mint-api-token-form');
    await form.getByLabel('Name').fill(label);
    await page.route('**/account/api-tokens', async (route) => {
        const response = await route.fetch({ maxRedirects: 0 });
        await new Promise((resolve) => setTimeout(resolve, 6000));
        await route.fulfill({ response });
    });
    await submitRedirectingForm(
        page,
        form.getByRole('button', { name: 'Create token' }),
        '/account/api-tokens',
    );
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
        await page.goto('/account?tab=api-tokens');
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
        await expect(page).toHaveURL('/account?tab=api-tokens');
        await expect(page.getByTestId('api-tokens-section')).toBeVisible();
        await expect(row).toHaveCount(0);
        expect((await api.get('/api/events')).status()).toBe(401);
        await page.reload();
        await expect(row).toHaveCount(0);
    } finally {
        await api.dispose();
    }
});
