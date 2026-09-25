/**
 * Browser coverage for the warning a card shows when its latest worker run
 * gave up. The runs go through the real run state endpoint with an agent
 * token, so no bridge runs.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { agentAccessToken, suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eRunWarning1!';

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
        form: { fullName: 'E2E Run Warning User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setBoardFlag(request, false);
});

test('a card whose latest run gave up shows a warning until a later run succeeds', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setBoardFlag(page.request, true);
    await registerAndLogin(page, `e2e+run-warning+${RUN}@example.com`);

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Run Warning Project', markdown: '# Runs' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Alpha');
    await page.getByLabel('Column').selectOption({ label: 'Backlog' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: 'Alpha' })).toBeVisible();

    const boardUrl = `/projects/${projectId}/board`;
    await page.goto(boardUrl);
    const card = page.locator('article[data-card-title="Alpha"]');
    const cardId = await card.getAttribute('data-card-id');
    expect(cardId).not.toBeNull();

    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const report = async (data: Record<string, unknown>) => {
        const response = await page.request.put(
            `/api/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
            {
                headers: { Authorization: `Bearer ${token}` },
                data: {
                    bridgeId,
                    at: '2026-09-23T10:00:00+00:00',
                    cardId,
                    cardNumber: 1,
                    ruleName: 'implement',
                    cardColumn: 'backlog',
                    sessionId: crypto.randomUUID(),
                    startedAt: '2026-09-23T10:00:00+00:00',
                    endedAt: '2026-09-23T10:05:00+00:00',
                    exitCode: 0,
                    hasResult: true,
                    ...data,
                },
            },
        );
        expect(response.status()).toBe(201);

        return (await response.json()).id as string;
    };

    const gaveUp = await report({
        state: 'gave-up',
        resultStatus: 'unfinished',
        output: 'CI still ran when the turn ended',
    });
    await page.goto(boardUrl);
    const warning = card.locator(`[data-card-run-warning="${gaveUp}"]`);
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('Gave up');
    await expect(warning).toContainText('CI still ran when the turn ended');

    await report({
        state: 'succeeded',
        resultStatus: 'finished',
        output: 'The pull request is ready',
    });
    await page.goto(boardUrl);
    await expect(card).toBeVisible();
    await expect(card.locator('[data-card-run-warning]')).toHaveCount(0);
});
