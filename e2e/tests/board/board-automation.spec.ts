/**
 * Browser coverage for the board automation settings: the owner opens the
 * Automation tab, changes every field, saves, and reads the values back after
 * a reload, because the server is what decides.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBoardAutomation1!';

// Under a loaded run, the POST and the redirected GET take longer than the 5 s default.
const ROUND_TRIP = { timeout: process.env.COVERAGE ? 20_000 : 15_000 };

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Automation User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

async function seedProject(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Automation Project', markdown: '# Automation' },
    });
    expect(response.status()).toBe(201);

    return (await response.json()).projectId as string;
}

test.use({ storageState: { cookies: [], origins: [] } });

test('the owner changes the automation settings and reads them back', async ({
    page,
}) => {
    // A sign-in, a seed, three page visits and a save outlast the default budget.
    test.slow();
    await suppressToolbar(page);
    await suppressWidget(page);
    await registerAndLogin(page, `e2e+automation+${RUN}@example.com`);
    const projectId = await seedProject(page);

    await page.goto(`/projects/${projectId}/settings/columns`);
    await page
        .getByRole('navigation', { name: 'Project settings' })
        .getByRole('link', { name: 'Automation' })
        .click();
    await expect(page).toHaveURL(`/projects/${projectId}/settings/automation`);

    const settings = page.locator('[data-board-automation-settings]');
    const enabled = settings.getByLabel('Run the workflow of the board');
    const epicDraftSwitch = settings.getByLabel(
        'Switch an epic pull request between draft and ready when the workflow asks',
    );
    const commentOnFixQueued = settings.getByLabel(
        'Comment on the pull request when a fix run is queued',
    );

    await expect(enabled).toBeChecked();
    await expect(epicDraftSwitch).not.toBeChecked();
    await expect(commentOnFixQueued).not.toBeChecked();

    await enabled.uncheck();
    await epicDraftSwitch.check();
    await commentOnFixQueued.check();
    await settings.getByRole('button', { name: 'Save automation' }).click();

    await expect(page.getByText('Automation settings saved.')).toBeVisible(
        ROUND_TRIP,
    );

    await page.reload();
    await expect(enabled).not.toBeChecked();
    await expect(epicDraftSwitch).toBeChecked();
    await expect(commentOnFixQueued).toBeChecked();
});
