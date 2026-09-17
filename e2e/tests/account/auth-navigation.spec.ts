import { test, expect } from '@playwright/test';
import { registerAndVerify, submitAuthForm } from '../helpers';

test.use({ storageState: { cookies: [], origins: [] } });

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
        await new Promise((resolve) => setTimeout(resolve, 6000));
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
        await new Promise((resolve) => setTimeout(resolve, 6000));
        await route.fulfill({ response });
    });
    await page.goto('/forgot-password');
    await page.getByLabel('Email').fill(`unknown-${Date.now()}@example.com`);
    await submitAuthForm(
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
        submitAuthForm(
            page,
            page.getByRole('button', { name: /reset/i }),
            '/forgot-password',
        ),
    ).rejects.toThrow(/302/);
});
