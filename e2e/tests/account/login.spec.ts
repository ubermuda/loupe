import { expect } from '@playwright/test';
import { testWithVerifiedAccount as test } from '../fixtures';
import { logout, submitRedirectingForm } from '../helpers';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

test('valid credentials log in and redirect to home', async ({
    page,
    verifiedAccount: { email, password },
}) => {
    await logout(page);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Sign in' }),
        '/login',
    );

    await expect(page).toHaveURL('/projects');
});

test('wrong password shows auth-error', async ({
    page,
    verifiedAccount: { email },
}) => {
    await logout(page);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('WrongPassword!');
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Sign in' }),
        '/login',
    );

    await expect(page).toHaveURL('/login');
    await expect(page.locator('.auth-error')).toBeVisible();
});

test('remember-me cookie survives browser restart', async ({
    page,
    context,
    verifiedAccount: { email, password },
}) => {
    await logout(page);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByLabel('Stay signed in on this device').check();
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Sign in' }),
        '/login',
    );
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
    await page.getByLabel('Display name').fill('Riley Chen');
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Create account' }),
        '/register',
    );
    await expect(page).toHaveURL('/register/check-email');
    // Do NOT click the verification link — skip straight to login

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('SecurePassword1!');
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Sign in' }),
        '/login',
    );

    // EmailVerificationSubscriber redirects unverified users to check-email
    await expect(page).toHaveURL('/register/check-email');
});
