/**
 * Browser coverage for the warning a card shows when its latest worker run
 * gave up. The runs go through the real run state endpoint with an agent
 * token, so no bridge runs. The warning appears and clears on an open board
 * with no navigation, so the run needs a Mercure hub the browser can reach.
 */

import { test, expect } from '@playwright/test';
import {
    agentAccessToken,
    signedInPage,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eRunWarning1!';

test('a card whose latest run gave up shows a warning until a later run succeeds', async ({
    browser,
    request,
}) => {
    // A sign-in, a card, a token and two live reports outlast the default budget.
    test.slow();

    const email = `e2e+run-warning+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Run Warning User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    // A page of its own context, because the hub refuses the project headers.
    const page = await signedInPage(browser, email, PASSWORD);
    await page.setViewportSize({ width: 1440, height: 900 });
    await suppressToolbar(page);
    await suppressWidget(page);

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Run Warning Project', markdown: '# Runs' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Alpha');
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(page.getByRole('heading', { name: 'Alpha' })).toBeVisible({
        timeout: 15_000,
    });

    const boardUrl = `/projects/${projectId}/board`;
    await page.goto(boardUrl);
    // The hub keeps no history, so a report sent before this connects is lost.
    await expect(page.locator('[data-board-live-connected]')).toHaveCount(1);
    const boardLoads: string[] = [];
    page.on('request', (request) => {
        if (new URL(request.url()).pathname === boardUrl) {
            boardLoads.push(request.url());
        }
    });
    const card = page.locator('article[data-card-title="Alpha"]');
    const cardId = await card.getAttribute('data-card-id');
    expect(cardId).not.toBeNull();

    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const report = async (data: Record<string, unknown>) => {
        // The bearer token alone signs the report, with no session to wait on.
        const response = await request.put(
            `/api/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
            {
                headers: { Authorization: `Bearer ${token}` },
                data: {
                    bridgeId,
                    at: '2026-09-23T10:00:00+00:00',
                    cardId,
                    cardNumber: 1,
                    workKind: 'implement',
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
    const warning = card.locator(`[data-card-run-warning="${gaveUp}"]`);
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('Gave up');
    await expect(warning).toContainText('CI still ran when the turn ended');

    await report({
        state: 'succeeded',
        resultStatus: 'finished',
        output: 'The pull request is ready',
    });
    await expect(card.locator('[data-card-run-warning]')).toHaveCount(0);
    await expect(card).toBeVisible();
    // Each run change places the one card, and never reloads the whole board.
    expect(boardLoads).toEqual([]);
});
