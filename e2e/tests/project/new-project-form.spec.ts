import { expect, test } from '@playwright/test';
import { registerAndVerify } from '../helpers';
import { suppressToolbar, suppressWidget } from '../fixtures';

test.use({ storageState: { cookies: [], origins: [] } });

/**
 * The create-project form lives behind a disclosure, so opening it is the moment
 * the reviewer means to start typing. Focus follows the disclosure rather than
 * the page, which is opt-in per field — the same controller collapses read-only
 * rows elsewhere and must not take the caret there.
 */
test('opening the new-project disclosure puts the caret in the name field', async ({
    page,
    request,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await registerAndVerify(page, request, {
        email: `e2e+new-project+${Date.now()}@example.com`,
        password: 'E2eNewProject1!',
    });

    await page.goto('/projects');
    await page.getByRole('button', { name: /new project/i }).click();

    await expect(page.getByLabel('Project name')).toBeFocused();
});
