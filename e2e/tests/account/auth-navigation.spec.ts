import { test, expect } from '@playwright/test';
import { registerAndVerify, submitRedirectingForm } from '../helpers';
import { coverageScaled } from '../timeouts';

test.use({ storageState: { cookies: [], origins: [] } });

test('login waits for a delayed POST before checking navigation', async ({
    page,
}) => {
    const email = `e2e-login-navigation-${Date.now()}@example.com`;
    const password = 'E2eAuthNavigation1!';
    const registration = await page.request.post('/dev/register-and-verify', {
        form: { email, password, fullName: 'Login navigation' },
    });
    expect(registration.status()).toBe(200);
    await page.route('**/login', async (route) => {
        if (route.request().method() !== 'POST') {
            await route.continue();
            return;
        }
        const response = await route.fetch({ maxRedirects: 0 });
        await new Promise((resolve) =>
            setTimeout(resolve, coverageScaled(6000)),
        );
        await route.fulfill({ response });
    });
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Sign in' }),
        '/login',
    );
    await expect(page).toHaveURL('/welcome');
});

test('registration waits for a delayed successful POST before checking navigation', async ({
    page,
    request,
}) => {
    await page.route('**/register', async (route) => {
        if (route.request().method() !== 'POST') {
            await route.continue();
            return;
        }
        const response = await route.fetch({ maxRedirects: 0 });
        await new Promise((resolve) =>
            setTimeout(resolve, coverageScaled(6000)),
        );
        await route.fulfill({ response });
    });
    await registerAndVerify(page, request, {
        email: `e2e-auth-navigation-${Date.now()}@example.com`,
        password: 'E2eAuthNavigation1!',
    });
    await expect(page).toHaveURL('/projects');
});

test('password reset waits for a delayed successful POST before checking navigation', async ({
    page,
}) => {
    await page.route('**/forgot-password', async (route) => {
        if (route.request().method() !== 'POST') {
            await route.continue();
            return;
        }
        const response = await route.fetch({ maxRedirects: 0 });
        await new Promise((resolve) =>
            setTimeout(resolve, coverageScaled(6000)),
        );
        await route.fulfill({ response });
    });
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(`unknown-${Date.now()}@example.com`);
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: /reset/i }),
        '/forgot-password',
    );
    await expect(page).toHaveURL('/forgot-password/check-email');
});

test('password reset waits for a delayed redirect response before checking navigation', async ({
    page,
}) => {
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(`unknown-${Date.now()}@example.com`);
    const network = await page.context().newCDPSession(page);
    await network.send('Network.enable');
    await network.send('Network.emulateNetworkConditions', {
        offline: false,
        latency: coverageScaled(6000),
        downloadThroughput: -1,
        uploadThroughput: -1,
    });
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: /reset/i }),
        '/forgot-password',
    );
    await expect(page).toHaveURL('/forgot-password/check-email');
});

test('auth submission reports a rejected POST instead of waiting for navigation', async ({
    page,
}) => {
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(`unknown-${Date.now()}@example.com`);
    await page.route('**/forgot-password', (route) =>
        route.fulfill({
            status: 422,
            contentType: 'text/html',
            body: '<p>Rejected submission</p>',
        }),
    );
    await expect(
        submitRedirectingForm(
            page,
            page.getByRole('button', { name: /reset/i }),
            '/forgot-password',
        ),
    ).rejects.toThrow(/302/);
});
