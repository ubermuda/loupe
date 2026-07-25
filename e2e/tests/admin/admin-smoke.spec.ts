import { expect } from '@playwright/test';
import { createTest } from '../fixtures';

// This email matches ADMIN_EMAIL in compose.yaml. The login the worker fixture
// performs is what triggers PromoteAdminUserListener — nothing in this spec
// grants ROLE_ADMIN, and there is no dev seam for it.
const test = createTest({
    email: 'e2e-admin-smoke@example.com',
    password: 'e2e_password_123',
    name: 'E2E Admin Smoke',
});

// A run that dies between "create" and "delete" would otherwise wedge every
// later run on the flag name's unique constraint.
const flagName = `e2e.smoke.${Date.now()}`;

test('a verified ADMIN_EMAIL user is promoted and sees the dashboard', async ({
    page,
}) => {
    await page.goto('/admin');

    await expect(
        page.getByRole('heading', { name: 'Dashboard' }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Feature Flags' }),
    ).toBeVisible();
});

test('a bool flag can be created, toggled and deleted', async ({ page }) => {
    await page.goto('/admin/feature-flags');
    await page.getByRole('link', { name: 'New flag' }).click();

    await expect(
        page.getByRole('heading', { name: 'New Feature Flag' }),
    ).toBeVisible();
    await page.getByLabel('Name', { exact: true }).fill(flagName);
    await page.getByLabel('Type', { exact: true }).selectOption('bool');
    await page.getByRole('button', { name: 'Save' }).click();

    const row = page.locator('tr', { hasText: flagName }).first();
    await expect(row).toBeVisible();
    // A new bool flag starts disabled.
    await expect(row.getByText('Disabled')).toBeVisible();

    // The toggle must visibly flip the state, not merely leave the row on
    // screen: the badge swaps from the neutral "Disabled" pill to the "on" one.
    await row.getByRole('button', { name: 'Enable' }).click();
    await expect(row.locator('.admin-badge-on')).toHaveText('Enabled');

    await row.getByRole('button', { name: 'Delete' }).click();
    // Deletion is confirmed through the shared modal Stimulus controller.
    await row
        .locator('.admin-dialog-box')
        .getByRole('button', { name: 'Delete flag' })
        .click();
    await expect(page.locator('tr', { hasText: flagName })).toHaveCount(0);
});
