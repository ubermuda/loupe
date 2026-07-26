import { expect, test } from '@playwright/test';
import { extractLink, getLatestEmailTo } from '../helpers';

/**
 * Runs in the dedicated `install-reset` project (after every chromium spec):
 * it wipes the database, so it can never share a run slot with other specs.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const ADMIN = {
    email: 'e2e-install-admin@example.com',
    password: 'e2e_password_123',
};

test('first-install wizard creates an unverified admin who is gated until they follow the emailed link', async ({
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

    // Done page: the admin exists but is unverified by design.
    await expect(
        page.getByText('Check your email to verify your account'),
    ).toBeVisible();

    // Logging in with the right password succeeds (verification is not a
    // login precondition), but ROLE_ADMIN access is still gated: an
    // unverified session is parked on the check-email page for any route
    // that isn't part of the login/verification flow.
    await page.goto('/login');
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: /sign in/i }).click();
    await page.goto('/admin/feature-flags');
    await expect(
        page.getByRole('heading', { name: 'Check your email' }),
    ).toBeVisible();

    // Following the emailed verification link verifies the account and logs
    // it in (VerifyEmailController authenticates on success), so ROLE_ADMIN
    // access now works and the seeded flag value survived.
    const received = await getLatestEmailTo(request, ADMIN.email);
    const verificationUrl = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/register\/verify[^\s"<]*/,
    );
    await page.goto(verificationUrl);
    await page.goto('/admin/feature-flags');
    // Scoped to the list row, not getByText: the page also has a (hidden)
    // delete-confirmation dialog whose title repeats the flag name, which
    // trips Playwright's strict-mode duplicate-match check.
    await expect(
        page.getByRole('cell', { name: 'registration.cap' }),
    ).toBeVisible();

    // The wizard is closed forever.
    const closed = await page.goto('/install');
    expect(closed?.status()).toBe(404);
});
