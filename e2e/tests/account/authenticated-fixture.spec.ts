import { expect } from '@playwright/test';
import { createTest } from '../fixtures';

// Also serves as the living proof that createTest works: it registers this
// user via Mailpit on the first run and logs in on subsequent runs.
const test = createTest({
    email: 'e2e-fixture-user@example.com',
    password: 'e2e_password_123!',
    name: 'Fixture User',
});

test('worker fixture provides an authenticated session', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveURL('/');
    await expect(page.locator('form[action="/logout"]')).toBeVisible();
});
