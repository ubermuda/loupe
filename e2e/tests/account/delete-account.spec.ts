import { test, expect } from '@playwright/test';
import {
    registerAndVerify,
    getEmailWithSubject,
    latestEmailIdWithSubject,
    extractLink,
} from '../helpers';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const DELETE_SUBJECT = 'Confirm your account deletion';

test('a user can delete their account end to end via the emailed confirmation link', async ({
    page,
    request,
}) => {
    const email = `e2e-delete-${RUN}@example.com`;
    const password = 'e2e_password_123!';

    // Throwaway user: this flow destroys the account, so it must never be the
    // shared worker user another spec file in this worker relies on.
    await registerAndVerify(page, request, {
        email,
        password,
    });

    // Mailpit is shared by every worktree and never cleared, so this address
    // could already hold deletion mail from an earlier run of this same spec
    // — mark the inbox before acting so we can tell "this run's email" apart
    // from anything already there.
    const previousDeletionEmail = await latestEmailIdWithSubject(
        request,
        email,
        DELETE_SUBJECT,
    );

    await page.goto('/account');
    await page
        .locator('[data-testid="delete-account-section"]')
        .getByRole('button', { name: 'Delete my account…' })
        .click();
    await expect(page).toHaveURL('/account');
    await expect(page.locator('.lp-flash--success')).toBeVisible();

    const received = await getEmailWithSubject(
        request,
        email,
        DELETE_SUBJECT,
        30000,
        previousDeletionEmail,
    );
    const confirmUrl = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/account\/delete\/confirm\?token=[^\s"<]+/,
    );

    await page.goto(confirmUrl);
    // The user is still logged in when following the link, so the normal
    // authenticated shell (sidebar with the account email) wraps the
    // confirmation content — scope to <main> or the email matches twice.
    await expect(page.getByRole('main').getByText(email)).toBeVisible();

    await page
        .getByRole('main')
        .getByRole('button', { name: 'Permanently delete my account' })
        .click();
    await expect(page.getByText('Your account has been deleted')).toBeVisible();

    // The old session is dead: the home page bounces to login.
    await page.goto('/');
    await expect(page).toHaveURL(/\/login/);

    // The credentials no longer resolve to anything.
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.locator('.auth-error')).toBeVisible();
});
