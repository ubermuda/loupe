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

    // Flags page: defaults are prefilled. Leave registrationCap at its default
    // (0 = unlimited) rather than setting a real limit — this spec runs in the
    // `install-reset` project, which always runs last, so any nonzero cap it
    // leaves behind becomes the *next* full-suite run's starting state and
    // silently closes registration for every other spec once enough users
    // exist. Int-value propagation through the form is already covered by
    // SeedInstallFlagsHandlerTest at the PHP level.
    await page.goto('/install');
    await expect(page.getByLabel('Trial length (days)')).toHaveValue('14');
    await expect(
        page.getByLabel('Registration cap (0 = unlimited)'),
    ).toHaveValue('0');
    await page.getByRole('button', { name: 'Continue' }).click();

    // Systems-check page. Only its presence and the way out are asserted: the
    // individual check results depend on what the surrounding environment
    // happens to have running (SMTP, the Mercure hub, a messenger worker), so
    // asserting any of them would be a flake dressed up as a test.
    await expect(
        page.getByRole('heading', { name: 'Check your systems' }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Continue' }).click();

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
    // Turbo submits the login as XHR, so the click resolves before the session
    // cookie exists. Navigating straight to /admin/feature-flags races that:
    // when the goto wins, the request is still anonymous and lands on /login,
    // where the heading below never appears. Wait for the logged-in landing
    // page — itself the parked check-email screen — before navigating away.
    await expect(
        page.getByRole('heading', { name: 'Check your email' }),
    ).toBeVisible();
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
    // Scoped to the list row, not a bare getByText: the page also has a
    // (hidden) delete-confirmation dialog whose title repeats the flag name,
    // which trips Playwright's strict-mode duplicate-match check. Asserting
    // the value cell shows "0" proves the seeded value flowed through the
    // whole pipeline without ever setting a registration-limiting number.
    const registrationCapRow = page.getByRole('row', {
        name: /registration\.cap/,
    });
    await expect(registrationCapRow).toBeVisible();
    await expect(
        registrationCapRow.getByText('0', { exact: true }),
    ).toBeVisible();

    // The wizard is closed forever.
    const closed = await page.goto('/install');
    expect(closed?.status()).toBe(404);
});
