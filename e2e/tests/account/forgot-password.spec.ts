import {
    test,
    expect,
    type Page,
    type APIRequestContext,
} from '@playwright/test';
import {
    getEmailWithSubject,
    extractLink,
    logout,
    registerAndVerify,
} from '../helpers';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

/**
 * Register a user and verify their email so they are fully active.
 * Returns with the browser on the home page (logged in).
 */
async function createVerifiedUser(
    page: Page,
    request: APIRequestContext,
    email: string,
    password: string,
): Promise<void> {
    await registerAndVerify(page, request, { email, password });
}

test('requesting reset with unknown email succeeds silently', async ({
    page,
}) => {
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill('nobody@example.com');
    await page.getByRole('button', { name: /reset/i }).click();
    await expect(page).toHaveURL('/forgot-password/check-email');
});

test('valid reset token allows password change', async ({ page, request }) => {
    const email = `test+reset+${RUN}@example.com`;
    await createVerifiedUser(page, request, email, 'OldPassword1!');
    await logout(page);

    // Request reset
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: /reset/i }).click();
    await expect(page).toHaveURL('/forgot-password/check-email');

    // Get reset link from email. Wait for the reset SUBJECT specifically:
    // email is delivered asynchronously, so "whatever is newest" at the first
    // poll can still be this test's own verification email.
    const received = await getEmailWithSubject(
        request,
        email,
        'Reset your password',
    );
    const resetLink = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/forgot-password\/reset\/[^\s"<]*/,
    );

    // Follow link — stores token in session and redirects to /forgot-password/reset
    await page.goto(resetLink);
    await expect(page).toHaveURL('/forgot-password/reset');

    // Submit new password
    await page
        .getByLabel('New password', { exact: true })
        .fill('NewPassword1!');
    await page.getByLabel('Repeat new password').fill('NewPassword1!');
    await page.getByRole('button', { name: /reset/i }).click();
    await expect(page).toHaveURL('/login');

    // Old password should no longer work
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('OldPassword1!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.locator('.auth-error')).toBeVisible();

    // New password works
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('NewPassword1!');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/projects');
});

test('used (already-consumed) token redirects to forgot-password with error', async ({
    page,
    request,
}) => {
    const email = `test+usedtoken+${RUN}@example.com`;
    await createVerifiedUser(page, request, email, 'OldPassword1!');
    await logout(page);

    // Request a reset link
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: /reset/i }).click();
    await expect(page).toHaveURL('/forgot-password/check-email');

    // Subject-matched for the same async-delivery reason as above.
    const received = await getEmailWithSubject(
        request,
        email,
        'Reset your password',
    );
    const resetLink = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/forgot-password\/reset\/[^\s"<]*/,
    );

    // Use the token once (successfully)
    await page.goto(resetLink);
    await expect(page).toHaveURL('/forgot-password/reset');
    await page
        .getByLabel('New password', { exact: true })
        .fill('NewPassword1!');
    await page.getByLabel('Repeat new password').fill('NewPassword1!');
    await page.getByRole('button', { name: /reset/i }).click();
    await expect(page).toHaveURL('/login');

    // Try to use the same link again — token is now consumed
    await page.goto(resetLink);
    // The same behavior as an invalid token: stored in session, controller validates, fails,
    // redirects to the request form.
    await expect(page).toHaveURL('/forgot-password');
});

test('invalid token redirects to forgot-password with error', async ({
    page,
}) => {
    // The token URL stores the token in session then immediately redirects to the form.
    // The form controller validates the bad token and redirects back to /forgot-password.
    await page.goto(`/forgot-password/reset/invalid-token-value`);
    await expect(page).toHaveURL('/forgot-password');
});
