/**
 * End-to-end coverage for the first-run wizard (/welcome): a fresh account is
 * routed there after verifying its email, walks through creating its first
 * project and connecting an agent, and can skip out at any step.
 */

import { test, expect } from '@playwright/test';
import { registerFreshUser } from '../helpers';

test.describe('first-run wizard', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('full flow: create project, connect, finish', async ({
        page,
        request,
    }) => {
        const email = `e2e-wizard-full-${Date.now()}@example.com`;
        await registerFreshUser(page, request, {
            email,
            password: 'e2e_password_123',
        });

        await expect(page).toHaveURL(/\/welcome$/);
        await expect(page.locator('ol[data-wizard-step="1"]')).toBeVisible();
        await page.getByLabel(/Project name/i).fill('Wizard project');
        await page.getByRole('button', { name: 'Create project' }).click();

        await expect(page).toHaveURL(/\/welcome\/connect$/);
        await expect(page.locator('ol[data-wizard-step="2"]')).toBeVisible();
        await expect(page.getByText('claude mcp add')).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Skip setup' }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Generate token' }).click();
        await expect(
            page.locator('[data-testid="minted-mcp-token"]'),
        ).toBeVisible();

        await page.getByRole('link', { name: 'Continue' }).click();
        await expect(page).toHaveURL(/\/welcome\/widget$/);
        await expect(page.locator('ol[data-wizard-step="3"]')).toBeVisible();

        await page.getByRole('button', { name: 'Generate token' }).click();
        await expect(
            page.locator('[data-testid="minted-widget-token"]'),
        ).toBeVisible();
        await expect(page.getByText('site-review/widget.js')).toBeVisible();

        await page.getByRole('link', { name: 'Continue' }).click();
        await expect(page).toHaveURL(/\/welcome\/done$/);
        await expect(page.locator('ol[data-wizard-step="4"]')).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Skip setup' }),
        ).toHaveCount(0);
        await page.getByRole('button', { name: 'Go to dashboard' }).click();

        await expect(page).toHaveURL(/\/projects\/[0-9a-f-]+\/documents$/);

        await page.goto('/welcome');
        await expect(page).not.toHaveURL(/\/welcome/);
    });

    test('skip from the first step', async ({ page, request }) => {
        const email = `e2e-wizard-skip-first-${Date.now()}@example.com`;
        await registerFreshUser(page, request, {
            email,
            password: 'e2e_password_123',
        });

        await expect(page).toHaveURL(/\/welcome$/);
        // This button sits inside the project form's action row and submits a
        // separate form through `form=`, because a nested <form> is invalid
        // HTML. Nothing else proves that wiring still submits.
        await page.getByRole('button', { name: 'Skip setup' }).click();
        await expect(page).toHaveURL(/\/projects$/);

        await page.goto('/welcome');
        await expect(page).not.toHaveURL(/\/welcome/);
    });

    test('skip from the connect step', async ({ page, request }) => {
        const email = `e2e-wizard-skip-${Date.now()}@example.com`;
        await registerFreshUser(page, request, {
            email,
            password: 'e2e_password_123',
        });

        await expect(page).toHaveURL(/\/welcome$/);
        await page.getByLabel(/Project name/i).fill('Skipped project');
        await page.getByRole('button', { name: 'Create project' }).click();
        await expect(page).toHaveURL(/\/welcome\/connect$/);

        await page.getByRole('button', { name: 'Skip setup' }).click();
        await expect(page).toHaveURL(/\/projects$/);

        await page.goto('/welcome');
        await expect(page).not.toHaveURL(/\/welcome/);
    });
});
