/**
 * A widget note lives on its card, and finishing the card resolves it. The
 * note goes in through the widget's own endpoint, the card moves to Done from
 * its page, and the card's Feedback tab then reads the note as resolved.
 */
import { expect } from '@playwright/test';
import {
    accessToken,
    createTest,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

const EMAIL = 'e2e-feedback-resolve@example.com';
const RUN = Date.now();
const test = createTest({
    email: EMAIL,
    password: 'E2eFeedbackResolve1!',
    name: 'Feedback Resolve Reviewer',
});

// Board pages run near the default budget beside three other workers.
test.slow();

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
});

test('moving a card to Done resolves the feedback on it', async ({ page }) => {
    const harness = await page.request.get(
        '/dev/site-review-harness?email=' + encodeURIComponent(EMAIL),
    );
    expect(harness.ok()).toBeTruthy();
    const projectId = /data-project="([^"]+)"/.exec(await harness.text())?.[1];
    expect(projectId).toBeTruthy();

    const body = `Resolve on done ${RUN}`;
    const saved = await page.request.post('/api/board/feedback', {
        headers: {
            Authorization:
                'Bearer ' +
                (await accessToken(page, 'site-review', projectId!)),
        },
        data: {
            body,
            url: 'https://example.com/checkout',
            anchors: [{ selector: '.hero', text: 'Heading' }],
            target: { newCard: {} },
        },
    });
    expect(saved.status()).toBe(201);
    const { cardId, commentId } = await saved.json();
    const cardUrl = `/projects/${projectId}/board/cards/${cardId}`;
    const entry = page.locator(
        `#card-panel-feedback [data-site-feedback="${commentId}"]`,
    );

    await page.goto(cardUrl);
    await page.getByRole('tab', { name: 'Feedback', exact: true }).click();
    await expect(entry).toContainText(body);
    await expect(entry.locator('.lp-status-chip')).toHaveText('Pending');

    await page.getByRole('tab', { name: 'Details', exact: true }).click();
    // The select submits on change, and the move answers with the board.
    await page
        .locator('#card-panel-details')
        .getByLabel('Column', { exact: true })
        .selectOption({ label: 'Done' });
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/board$`));

    await page.goto(cardUrl);
    await page.getByRole('tab', { name: 'Feedback', exact: true }).click();
    await expect(entry.locator('.lp-status-chip')).toHaveText('Resolved');
});
