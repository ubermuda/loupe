import { expect, test } from '@playwright/test';
import { fetchVerificationUrl } from '../helpers';

/**
 * Runs in the dedicated `install-reset` project (after every chromium spec):
 * it wipes the database, so it can never share a run slot with other specs.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const ADMIN = {
    email: 'e2e-install-admin@example.com',
    password: 'e2e_password_123',
};

test('first-install wizard seeds flags and creates a verified admin', async ({
    page,
    request,
}) => {
    const reset = await request.post('/dev/e2e/reset');
    expect(reset.status(), 'DB reset failed').toBe(200);

    // Flags page: defaults are prefilled; bump the cap so we can see it stick.
    await page.goto('/install');
    await expect(page.getByLabel('Trial length (days)')).toHaveValue('14');
    await page.getByLabel('Registration cap (0 = unlimited)').fill('25');
    await page.getByRole('button', { name: 'Continue' }).click();

    // Admin-account page.
    await expect(
        page.getByRole('heading', { name: 'Create your admin account' }),
    ).toBeVisible();
    await page.getByLabel('Full name').fill('E2E Admin');
    await page.getByLabel('Username').fill('e2e-admin');
    await page.getByLabel('Email address').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Create admin account' }).click();

    // Done page → verify via Mailpit → login.
    await expect(
        page.getByText('Check your email to verify your account'),
    ).toBeVisible();
    const verificationUrl = await fetchVerificationUrl(request, ADMIN.email);
    await page.goto(verificationUrl);
    await page.goto('/login');
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: /sign in/i }).click();

    // ROLE_ADMIN works and the seeded flag value survived.
    await page.goto('/admin/feature-flags');
    await expect(page.getByText('registration.cap')).toBeVisible();

    // The wizard is closed forever.
    const closed = await page.goto('/install');
    expect(closed?.status()).toBe(404);
});
