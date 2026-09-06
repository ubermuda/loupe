import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { type Credentials, registerAndVerify } from './helpers';
import { coverageScaled } from './timeouts';

/**
 * Matches ADMIN_EMAIL in compose.yaml (see admin-smoke.spec.ts) — logging in
 * as this address is what PromoteAdminUserListener keys on to grant
 * ROLE_ADMIN, needed to edit feature flags through the admin UI.
 */
export const ADMIN: Credentials = {
    email: 'e2e-admin-smoke@example.com',
    password: 'e2e_password_123',
};

/**
 * Logs the admin in on its own page, registering the account first if this
 * database has never seen it — the same login-or-register flow as the
 * createTest worker fixture. Use from specs whose worker fixture is a
 * NON-admin user but which need an admin session on a second page.
 */
export async function adminLogin(
    page: Page,
    request: APIRequestContext,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(
        page.locator('form[action="/logout"]').or(page.locator('.auth-error')),
    ).toBeVisible();

    if (await page.locator('.auth-error').isVisible()) {
        await registerAndVerify(page, request, ADMIN);
    }

    await expect(page).toHaveURL('/projects');
}

/**
 * Creates or edits the singleton `registration.cap` int flag via the real
 * admin UI.
 *
 * The edit form (unlike create) redirects back to the SAME edit URL on
 * success, so a toHaveURL(/\/admin\/feature-flags/) assertion here would
 * already match before the click and resolve without ever waiting for the
 * Turbo-driven POST to round-trip — the classic "form redirects to the same
 * URL" trap. Re-navigate and poll for the persisted value on the list page
 * instead: the one signal that proves the save actually landed, regardless
 * of which of the two controllers handled it.
 */
export async function setRegistrationCap(
    admin: Page,
    value: number,
): Promise<void> {
    await admin.goto('/admin/feature-flags?q=registration.cap');
    const existingRow = admin.locator('tr', { hasText: 'registration.cap' });

    if (0 === (await existingRow.count())) {
        await admin
            .getByRole('link', { name: 'New flag', exact: true })
            .click();
        await expect(
            admin.getByRole('heading', {
                name: 'New Feature Flag',
                exact: true,
            }),
        ).toBeVisible();
        await admin
            .getByLabel('Name', { exact: true })
            .fill('registration.cap');
    } else {
        await existingRow
            .getByRole('link', { name: 'Edit', exact: true })
            .click();
        await expect(
            admin.getByRole('heading', {
                name: 'Edit Feature Flag',
                exact: true,
            }),
        ).toBeVisible();
    }

    await admin.getByLabel('Type', { exact: true }).selectOption('int');
    await admin
        .locator('[data-feature-flag-form-target="intField"]')
        .getByLabel('Value', { exact: true })
        .fill(String(value));
    await admin.getByRole('button', { name: 'Save' }).click();

    await expect(async () => {
        await admin.goto('/admin/feature-flags?q=registration.cap');
        await expect(
            admin
                .locator('tr', { hasText: 'registration.cap' })
                .getByText(String(value), { exact: true }),
        ).toBeVisible();
    }).toPass({ timeout: coverageScaled(15000) });
}
