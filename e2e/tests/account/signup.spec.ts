import { test, expect } from '@playwright/test';
import { getLatestEmailTo } from '../helpers';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

test('renders signup form', async ({ page }) => {
    await page.goto('/register');
    await expect(page.locator('h1')).toContainText('Create your account');
    await expect(page.getByLabel('Email')).toBeVisible();
    await expect(page.getByLabel('Display name')).toBeVisible();
    await expect(page.getByLabel('Password')).toBeVisible();
});

test('shows validation errors on empty submit', async ({ page }) => {
    await page.goto('/register');
    // Submit with only the email filled — password and the terms box empty.
    await page.getByLabel('Email').fill('incomplete@example.com');
    await page.getByRole('button', { name: 'Create account' }).click();

    // Should stay on the form, not redirect
    await expect(page).toHaveURL('/register');
    // At least one field error should appear
    await expect(page.locator('form ul li').first()).toBeVisible();
});

test('successful signup redirects to check-email page', async ({ page }) => {
    await page.goto('/register');
    await page.getByLabel('Email').fill(`test+signup+${RUN}@example.com`);
    await page.getByLabel('Display name').fill('Riley Chen');
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page).toHaveURL('/register/check-email');
    await expect(page.locator('h1')).toContainText('Check your email');
});

test('sends verification email after signup', async ({ page, request }) => {
    const email = `test+emailsend+${RUN}@example.com`;
    await page.goto('/register');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Display name').fill('Riley Chen');
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();

    const received = await getLatestEmailTo(request, email);

    expect(received.subject).toBe('Confirm your account');
    expect(received.body).toContain('/register/verify');
});

test('shows error on duplicate email', async ({ page }) => {
    const email = `test+dup+${RUN}@example.com`;

    // First registration
    await page.goto('/register');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Display name').fill('Riley Chen');
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/register/check-email');

    // Second registration with same email
    await page.goto('/register');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Display name').fill('Riley Chen');
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page.locator('body')).toContainText(
        'There is already an account with this email.',
    );
});
