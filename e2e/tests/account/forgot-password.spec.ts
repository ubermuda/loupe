import {
    test,
    expect,
    type Page,
    type APIRequestContext,
} from '@playwright/test';
import { getLatestEmailTo, extractLink } from '../helpers';

const RUN = Date.now();

/**
 * Register a user and verify their email so they are fully active.
 * Returns with the browser on the home page (logged in).
 */
async function createVerifiedUser(
    page: Page,
    request: APIRequestContext,
    email: string,
    username: string,
    password: string,
): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Full name').fill('Reset Test');
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/register/check-email');

    const received = await getLatestEmailTo(request, email);
    const link = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/register\/verify[^\s"<]*/,
    );
    await page.goto(link);
    await expect(page).toHaveURL('/documents');
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
    await createVerifiedUser(
        page,
        request,
        email,
        `resetuser${RUN}`,
        'OldPassword1!',
    );
    await page.goto('/logout');

    // Request reset
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: /reset/i }).click();
    await expect(page).toHaveURL('/forgot-password/check-email');

    // Get reset link from email
    const received = await getLatestEmailTo(request, email);
    expect(received.subject).toBe('Reset your password');
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
    await expect(page).toHaveURL('/documents');
});

test('used (already-consumed) token redirects to forgot-password with error', async ({
    page,
    request,
}) => {
    const email = `test+usedtoken+${RUN}@example.com`;
    await createVerifiedUser(
        page,
        request,
        email,
        `usedtoken${RUN}`,
        'OldPassword1!',
    );
    await page.goto('/logout');

    // Request a reset link
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: /reset/i }).click();
    await expect(page).toHaveURL('/forgot-password/check-email');

    const received = await getLatestEmailTo(request, email);
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
