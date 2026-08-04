import {
    test,
    expect,
    type Page,
    type APIRequestContext,
} from '@playwright/test';
import { logout, registerAndVerify } from '../helpers';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

/**
 * Register a user and click the verification link so they end up fully verified.
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

test('valid credentials log in and redirect to home', async ({
    page,
    request,
}) => {
    const email = `test+login+${RUN}@example.com`;
    await createVerifiedUser(page, request, email, 'SecurePassword1!');

    await logout(page);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL('/projects');
});

test('wrong password shows auth-error', async ({ page, request }) => {
    const email = `test+badpw+${RUN}@example.com`;
    await createVerifiedUser(page, request, email, 'SecurePassword1!');

    await logout(page);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('WrongPassword!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL('/login');
    await expect(page.locator('.auth-error')).toBeVisible();
});

test('remember-me cookie survives browser restart', async ({
    page,
    context,
    request,
}) => {
    const email = `test+remember+${RUN}@example.com`;
    await createVerifiedUser(page, request, email, 'SecurePassword1!');

    await logout(page);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('Stay signed in on this device').check();
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/projects');

    // Grab cookies from the current context
    const cookies = await context.cookies();
    const rememberMe = cookies.find((c) => c.name === 'REMEMBERME');
    expect(rememberMe).toBeDefined();

    // Simulate browser restart: create a fresh page with only the remember-me cookie
    const newPage = await context.newPage();
    // Clear session cookies by going to a data: URL first (forces fresh session)
    await newPage.context().clearCookies();
    await newPage.context().addCookies([rememberMe!]);

    await newPage.goto('/');
    // With remember-me cookie, should be authenticated (no redirect to /login)
    await expect(newPage).toHaveURL('/projects');
});

test('unverified user after login is redirected to check-email', async ({
    page,
}) => {
    const email = `test+unverified+${RUN}@example.com`;

    await page.goto('/register');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();
    // Wait for registration to complete before navigating away — clicking the
    // button fires a Turbo XHR; going to /login immediately can abort it.
    await expect(page).toHaveURL('/register/check-email');
    // Do NOT click the verification link — skip straight to login

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    // EmailVerificationSubscriber redirects unverified users to check-email
    await expect(page).toHaveURL('/register/check-email');
});
