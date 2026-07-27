import { test, expect, type Page } from '@playwright/test';
import { countEmailsTo, getLatestEmailTo, extractLink } from '../helpers';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

async function signUp(
    page: Page,
    email: string,
    username: string,
): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Full name').fill('Verify Test');
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/register/check-email');
}

test('clicking verification link auto-logs in and redirects to home', async ({
    page,
    request,
}) => {
    const email = `test+verify+${RUN}@example.com`;
    await signUp(page, email, `verifyuser${RUN}`);

    const received = await getLatestEmailTo(request, email);
    const verifyLink = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/register\/verify[^\s"<]*/,
    );

    await page.goto(verifyLink);
    // Verification auto-logs in and redirects to home; a brand-new account
    // owns no project and hasn't completed the first-run wizard yet, so
    // HomeController lands it there rather than on /login.
    await expect(page).toHaveURL('/welcome');
});

test('tampered verification link redirects to check-email', async ({
    page,
}) => {
    await page.goto(
        '/register/verify?id=nonexistent&token=fakefaketoken&expires=1',
    );
    await expect(page).toHaveURL('/register/check-email');
});

test('missing id parameter redirects to check-email', async ({ page }) => {
    await page.goto('/register/verify?token=sometoken&expires=9999999999');
    await expect(page).toHaveURL('/register/check-email');
});

test('resend sends a new verification email', async ({ page, request }) => {
    const email = `test+resend+${RUN}@example.com`;
    await signUp(page, email, `resenduser${RUN}`);

    // Wait for the signup email so the resend assertion below can't pass on it
    await expect.poll(() => countEmailsTo(request, email)).toBe(1);

    // The check-email page has the resend form
    await page
        .getByRole('button', { name: 'Resend verification email' })
        .click();
    await expect(page).toHaveURL('/register/check-email');

    // A second message for this address proves resend actually sent one —
    // the subject alone can't, since the signup email has the same subject.
    await expect
        .poll(() => countEmailsTo(request, email), { timeout: 10000 })
        .toBe(2);
    const received = await getLatestEmailTo(request, email);
    expect(received.subject).toBe('Confirm your account');
});
