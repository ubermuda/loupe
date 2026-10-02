/**
 * The run and agent controls on the card page. The heartbeat and the run go
 * through the real bridge endpoints, so no bridge runs and no request settles.
 */

import { expect } from '@playwright/test';
import { agentAccessToken, createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-worker-controls-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: 0 },
    });
    expect(response.ok()).toBeTruthy();
});

test('the owner stops a run, which holds nothing, then pauses and releases the agents', async ({
    page,
}) => {
    // A card, a board load, a token and two reports outlast the default budget on CI.
    test.slow();
    await suppressWidget(page);
    const flag = await page.request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: 1 },
    });
    expect(flag.ok()).toBeTruthy();

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Worker Controls', markdown: '# Controls' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Alpha');
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: 'Alpha' })).toBeVisible();
    await page.goto(`/projects/${projectId}/board`);
    const cardId = await page
        .locator('article[data-card-title="Alpha"]')
        .getAttribute('data-card-id');
    expect(cardId).not.toBeNull();

    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const heartbeat = await page.request.put(
        `/api/bridges/${bridgeId}/heartbeat`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: {
                projects: [projectId],
                cliVersion: '1.5.0',
                capabilities: ['commands'],
            },
        },
    );
    expect(heartbeat.status()).toBe(200);
    const report = await page.request.put(
        `/api/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: {
                bridgeId,
                state: 'running',
                at: new Date().toISOString(),
                cardId,
                cardNumber: 1,
                ruleName: 'implement',
                cardColumn: 'next',
                sessionId: crypto.randomUUID(),
                startedAt: new Date().toISOString(),
            },
        },
    );
    expect(report.status()).toBe(201);
    const runId = (await report.json()).id as string;

    await page.goto(`/projects/${projectId}/board/cards/${cardId}`);
    const row = page.locator(`[data-card-run-row="${runId}"]`);
    const label = row.locator('[data-worker-run-control-label]');
    const held = page.locator('[data-card-held]');
    const stop = row.getByRole('button', { name: 'Stop', exact: true });
    await expect(stop).toBeEnabled();
    await expect(held).toHaveCount(0);

    await stop.click();
    await expect(label).toHaveText('Stop requested');
    await expect(held).toHaveCount(0);

    await row
        .getByRole('button', { name: 'Cancel request', exact: true })
        .click();
    await expect(stop).toBeEnabled();
    await expect(label).toHaveCount(0);
    await expect(held).toHaveCount(0);

    const runs = page.locator('turbo-frame#card-worker-runs');
    await runs
        .getByRole('button', { name: 'Pause agents', exact: true })
        .click();
    await expect(held).toBeVisible();
    await expect(held).toContainText(
        'Agents paused: no worker starts on this card until you let agents run or move it.',
    );
    const release = runs.getByRole('button', {
        name: 'Let agents run',
        exact: true,
    });
    await expect(release).toBeVisible();
    await expect(
        runs.getByRole('button', { name: 'Pause agents', exact: true }),
    ).toHaveCount(0);

    await release.click();
    await expect(held).toHaveCount(0);
    await expect(
        runs.getByRole('button', { name: 'Pause agents', exact: true }),
    ).toBeVisible();
});
