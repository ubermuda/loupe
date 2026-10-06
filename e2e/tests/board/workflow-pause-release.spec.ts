/**
 * A person ends a workflow pause from the Workflow panel of the card page, and
 * the History tab records the release.
 */

import { expect } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-workflow-pause-${Date.now()}@example.com`,
    password: 'E2eWorkflowPause1!',
});

// Under a loaded run, the POST and the redirected GET take longer than the 5 s default.
const ROUND_TRIP = { timeout: process.env.COVERAGE ? 20_000 : 15_000 };

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
});

test('Retry now ends a retries pause, and the history records the release', async ({
    page,
}) => {
    test.slow();
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Workflow Pause', markdown: '# Pause' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();

    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title', { exact: true }).fill('Paused card');
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await page
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Paused card', exact: true }),
    ).toBeVisible();
    const cardUrl = new URL(page.url()).pathname;
    const cardId = /\/cards\/([0-9a-f-]+)$/.exec(cardUrl)?.[1];
    expect(cardId).toBeTruthy();

    const pause = await page.request.post(
        `/dev/seed/workflow-pause/${projectId}/${cardId}`,
    );
    expect(pause.status()).toBe(201);

    await page.goto(cardUrl);
    const panel = page.locator('[data-workflow-panel]');
    await expect(panel.locator('[data-workflow-pause]')).toContainText(
        'too many attempts were refused',
    );
    await panel.getByRole('button', { name: 'Retry now' }).click();

    await expect(
        page.getByText('The pause ended. The workflow runs the rule again.'),
    ).toBeVisible(ROUND_TRIP);
    await expect(panel.locator('[data-workflow-pause]')).toHaveCount(0);

    await page.getByRole('tab', { name: 'History', exact: true }).click();
    await expect(
        page
            .locator('[data-card-history-entry]')
            .filter({ hasText: 'released a pause' }),
    ).toHaveCount(1);
});
