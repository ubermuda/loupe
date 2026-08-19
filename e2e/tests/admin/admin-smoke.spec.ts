import { expect } from '@playwright/test';
import { createTest } from '../fixtures';

// This email matches ADMIN_EMAIL in compose.yaml. The login the worker fixture
// performs is what triggers PromoteAdminUserListener — nothing in this spec
// grants ROLE_ADMIN, and there is no dev seam for it.
const test = createTest({
    email: 'e2e-admin-smoke@example.com',
    password: 'e2e_password_123',
});

// A run that dies between "create" and "delete" would otherwise wedge every
// later run on the flag name's unique constraint.
const flagName = `e2e.smoke.${Date.now()}`;

test('a verified ADMIN_EMAIL user is promoted and sees the dashboard', async ({
    page,
}) => {
    await page.goto('/admin');

    await expect(
        page.getByRole('heading', { name: 'Dashboard', exact: true }),
    ).toBeVisible();
    // exact: true is required — accessible-name matching is substring by
    // default, and the dashboard card's "Manage feature flags" link would
    // otherwise match alongside the sidebar entry.
    await expect(
        page.getByRole('link', { name: 'Feature Flags', exact: true }),
    ).toBeVisible();
});

test('the admin layout actually loads the stylesheet', async ({ page }) => {
    await page.goto('/admin');

    await expect(page.locator('body')).toHaveCSS('display', 'flex');
});

test('the site-review widget reaches the admin area too', async ({ page }) => {
    const widget = 'script[src="/site-review/widget.js"]';

    await page.goto('/');
    const configured = await page.locator(widget).count();
    test.skip(configured === 0, 'SITE_REVIEW_WIDGET_TOKEN is not set here');

    await page.goto('/admin');
    await expect(page.locator(widget)).toHaveCount(1);
});

test('a bool flag can be created, toggled and deleted', async ({ page }) => {
    await page.goto('/admin/feature-flags');
    await page.getByRole('link', { name: 'New flag', exact: true }).click();

    await expect(
        page.getByRole('heading', { name: 'New Feature Flag', exact: true }),
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
    await row.getByRole('button', { name: 'Enable', exact: true }).click();
    await expect(row.locator('.admin-badge-on')).toHaveText('Enabled');

    // exact: true keeps this off the dialog's "Delete flag" button, which is
    // in the same row (a closed <dialog> is display:none, so it is out of the
    // accessibility tree here — but the trigger should not rely on that).
    await row.getByRole('button', { name: 'Delete', exact: true }).click();
    // Deletion is confirmed through the shared modal Stimulus controller.
    await row
        .locator('.admin-dialog-box')
        .getByRole('button', { name: 'Delete flag' })
        .click();
    await expect(page.locator('tr', { hasText: flagName })).toHaveCount(0);
});
