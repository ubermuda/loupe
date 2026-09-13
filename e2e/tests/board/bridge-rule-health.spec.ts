/**
 * Browser coverage for bridge rule health. A report is sent through the real
 * endpoint with an agent token minted on the account page, so no bridge runs.
 * The board then shows a banner for the dead rule, and the rename and delete
 * dialogs of a watched column warn before they save.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBridgeRules1!';
const COLUMN = '[data-board-columns-target="column"]';

async function setBoardFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Rules User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome');
}

async function seedProject(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Rules Project', markdown: '# Rules' },
    });
    expect(response.status()).toBe(201);

    return (await response.json()).projectId as string;
}

async function mintAgentToken(page: Page): Promise<string> {
    await page.goto('/account');
    const form = page.getByTestId('mint-api-token-form');
    await form.getByLabel('Name').fill('E2E bridge');
    await form.getByRole('button', { name: 'Create token' }).click();

    const value = page.getByTestId('minted-api-token-value');
    await expect(value).toBeVisible();

    return (await value.textContent())?.trim() ?? '';
}

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setBoardFlag(request, false);
});

test('a dead rule shows a banner and a watched column warns before a rename or a delete', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setBoardFlag(page.request, true);
    await registerAndLogin(page, `e2e+bridge-rules+${RUN}@example.com`);

    const projectId = await seedProject(page);
    const token = await mintAgentToken(page);
    expect(token).not.toBe('');

    const report = await page.request.put(
        `/api/projects/${projectId}/bridges/${crypto.randomUUID()}/rules`,
        {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
            },
            data: {
                rules: [
                    {
                        name: 'plan',
                        on: 'board.card_moved',
                        columns: ['ready'],
                        state: 'dead',
                        reason: 'column_renamed',
                    },
                    {
                        name: 'review',
                        on: 'board.card_moved',
                        columns: ['in-progress'],
                        state: 'live',
                        reason: null,
                    },
                ],
            },
        },
    );
    expect(report.status()).toBe(204);

    await page.goto(`/projects/${projectId}/board`);
    const banner = page.getByTestId('bridge-rules-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('A bridge reports a dead rule');
    await expect(banner.locator('[data-bridge-rule="plan"]')).toContainText(
        'column_renamed',
    );
    await expect(banner).not.toContainText('review');

    const column = page.locator(`${COLUMN}[data-column-slug="in-progress"]`);
    await column.locator('.lp-board__column-menu-trigger').click();
    await column.getByRole('button', { name: 'Rename' }).click();
    const rename = page.locator('dialog[open]');
    await expect(rename.locator('.lp-board__rule-warning')).toContainText(
        'A bridge rule watches this column.',
    );
    await rename.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);

    await column.getByRole('button', { name: 'Delete column' }).click();
    const remove = page.locator('dialog[open]');
    await expect(remove.locator('.lp-board__rule-warning')).toContainText(
        'Deleting it removes its slug',
    );
});
