/**
 * Browser coverage for the state mark a tile shows when its latest worker run
 * gave up or failed. The runs go through the real run state endpoint with an agent
 * token, so no bridge runs. The mark appears and clears on an open board
 * with no navigation, so the run needs a Mercure hub the browser can reach.
 * The tile holds one mark and no run warning. Its tooltip links to the card.
 */

import { test, expect } from '@playwright/test';
import {
    agentAccessToken,
    signedInPage,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eStateMark1!';

test('a card shows a stuck mark until a newer run of the card succeeds or starts', async ({
    browser,
    request,
}) => {
    // A sign-in, a card, a token and four live reports outlast the default budget.
    test.slow();

    const email = `e2e+state-mark+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E State Mark User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    // A page of its own context, because the hub refuses the project headers.
    const page = await signedInPage(browser, email, PASSWORD);
    await page.setViewportSize({ width: 1440, height: 900 });
    await suppressToolbar(page);
    await suppressWidget(page);

    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E State Mark Project', markdown: '# Runs' },
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
                    subjectType: 'card',
                    subjectId: cardId,
                    cardNumber: 1,
                    workKind: 'implement',
                    ...data,
                },
            },
        );
        expect(response.status()).toBe(201);

        return (await response.json()).id as string;
    };

    const outcome = {
        sessionId: crypto.randomUUID(),
        startedAt: '2026-09-23T10:00:00+00:00',
        endedAt: '2026-09-23T10:05:00+00:00',
        exitCode: 0,
        hasResult: true,
    };
    await report({
        ...outcome,
        state: 'gave-up',
        resultStatus: 'unfinished',
        output: 'CI still ran when the turn ended',
    });
    const mark = card.locator('.lp-state-mark--stuck');
    await expect(mark).toHaveCount(1);
    await expect(mark.getByRole('img', { name: 'Stuck' })).toBeVisible();
    await expect(card.locator('.lp-state-mark')).toHaveCount(1);
    await expect(card.locator('[data-card-run-warning]')).toHaveCount(0);
    await expect(card.locator('.lp-board-card__badges')).toHaveCount(0);

    await mark.getByRole('img', { name: 'Stuck' }).hover();
    const tooltip = mark.getByRole('tooltip');
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText('The last run ended as gave-up.');
    // The pointer moves from the mark into the tooltip, which stays open.
    await tooltip.getByRole('link', { name: 'Open card' }).hover();
    await expect(tooltip).toBeVisible();

    await report({
        ...outcome,
        state: 'succeeded',
        resultStatus: 'finished',
        output: 'The pull request is ready',
    });
    await expect(card.locator('.lp-state-mark--stuck')).toHaveCount(0);
    await expect(card).toBeVisible();

    await report({
        ...outcome,
        state: 'failed',
        exitCode: 1,
        output: 'The tests failed',
    });
    await expect(card.locator('.lp-state-mark--stuck')).toHaveCount(1);

    // A queued retry is the newest run of the card, so the stuck mark goes before it ends.
    await report({ state: 'queued' });
    await expect(card.locator('.lp-state-mark--stuck')).toHaveCount(0);
    await expect(card).toBeVisible();
    // Each run change places the one card, and never reloads the whole board.
    expect(boardLoads).toEqual([]);

    // The link in the tooltip opens the card in the drawer.
    await report({
        ...outcome,
        state: 'failed',
        exitCode: 1,
        output: 'The tests failed again',
    });
    const reopened = card.locator('.lp-state-mark--stuck');
    await expect(reopened).toHaveCount(1);
    await reopened.getByRole('img', { name: 'Stuck' }).focus();
    await reopened.getByRole('link', { name: 'Open card' }).click();
    await expect(
        page.locator('dialog.lp-card-drawer-overlay'),
    ).toHaveJSProperty('open', true);
});
