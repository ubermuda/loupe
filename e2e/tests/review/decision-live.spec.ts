/**
 * An answer saved in one tab reaches another tab of the same document, with
 * the summary and the name of who changed it. The hub delivers the change, so
 * the run needs a Mercure hub the browser can reach.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { signedInPage, suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eDecisionLive1!';
const FULL_NAME = 'E2E Live Decisions';
const DECISION_ID = 'rollout-order';
const NOTE = 'Readers first, then the data.';

const MARKDOWN = `# Rollout

<!-- decision: ${DECISION_ID} -->

1. Ship the migration first
2. Ship the reader first

<!-- /decision -->

Some prose after the decision.`;

async function setFlag(
    request: APIRequestContext,
    name: string,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name, enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function saving(page: Page, action: () => Promise<void>): Promise<void> {
    const response = page.waitForResponse(
        (each) =>
            each.url().endsWith('/decisions/answer') &&
            each.request().method() === 'POST',
        { timeout: coverageScaled(15000) },
    );
    await action();
    expect((await response).status()).toBe(200);
}

async function openReview(page: Page, reviewUrl: string): Promise<void> {
    await suppressToolbar(page);
    await suppressWidget(page);
    await page.goto(reviewUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(
        page.locator('[data-controller~="decision"][data-decision-connected]'),
    ).toHaveCount(1, { timeout: coverageScaled(15000) });
}

const options = (page: Page) =>
    page
        .locator(`[data-decision-id="${DECISION_ID}"]`)
        .locator('input[type="radio"][data-decision-option]');

const note = (page: Page) =>
    page.locator(`[data-decision-id="${DECISION_ID}"] textarea`);

test('an answer saved in one tab shows in another tab of the document', async ({
    browser,
    request,
}) => {
    // Takes about 16 s warm. A cold worktree ran out of the 30 s default once.
    test.slow();
    await setFlag(request, 'live_updates.enabled', true);

    const email = `e2e+decisionlive+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: FULL_NAME, email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const author = await signedInPage(browser, email, PASSWORD);
    const seeded = await author.request.post('/dev/seed/document', {
        form: { title: 'Decision live', markdown: MARKDOWN },
    });
    expect(seeded.status()).toBe(201);
    const body = (await seeded.json()) as {
        documentId: string;
        projectId: string;
    };
    const reviewUrl = `/projects/${body.projectId}/documents/${body.documentId}/review`;

    // A second tab of the same session, so both pages share one user.
    const watcher = await author.context().newPage();
    await openReview(author, reviewUrl);
    await openReview(watcher, reviewUrl);
    await expect(watcher.locator('#decision-summary-count')).toHaveText(
        '0 of 1 answered',
    );

    await saving(author, () => options(author).nth(1).check());

    await expect(options(watcher).nth(1)).toBeChecked({
        timeout: coverageScaled(15000),
    });
    await expect(options(watcher).nth(0)).not.toBeChecked();
    await expect(watcher.locator('#decision-summary-count')).toHaveText(
        '1 of 1 answered',
    );
    await expect(watcher.locator('#decision-status')).toHaveText(
        `Changed by ${FULL_NAME}.`,
    );

    await saving(author, () => note(author).fill(NOTE));

    await expect(note(watcher)).toHaveValue(NOTE, {
        timeout: coverageScaled(15000),
    });
    await expect(options(watcher).nth(1)).toBeChecked();
});
