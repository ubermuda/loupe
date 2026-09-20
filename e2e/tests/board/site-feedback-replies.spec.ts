import { expect } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const EMAIL = 'e2e-site-feedback-replies@example.com';
const RUN = Date.now();
const test = createTest({
    email: EMAIL,
    password: 'E2eSiteReplies1!',
    name: 'Site Reply Reviewer',
});

test.afterAll(async ({ request }) => {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: 0 },
    });
    expect(response.ok()).toBeTruthy();
});

for (const surface of ['page', 'drawer']) {
    test(
        'feedback replies survive attachment and submission failure on the ' +
            surface,
        async ({ page }) => {
            await suppressToolbar(page);
            await suppressWidget(page);
            const flag = await page.request.post('/dev/e2e/feature-flag', {
                form: { name: 'board.enabled', enabled: 1 },
            });
            expect(flag.ok()).toBeTruthy();
            const harness = await page.request.get(
                '/dev/site-review-harness?email=' + encodeURIComponent(EMAIL),
            );
            expect(harness.ok()).toBeTruthy();
            const token = /data-token="([^"]+)"/.exec(
                await harness.text(),
            )?.[1];
            expect(token).toBeTruthy();
            const created = await page.request.post(
                '/api/site-review/comments',
                {
                    headers: { Authorization: 'Bearer ' + token },
                    data: {
                        body: 'Keep this original capture ' + surface + RUN,
                        url: 'https://example.com/captured-page',
                        anchors: [
                            { selector: '.hero', text: 'Original heading' },
                        ],
                    },
                },
            );
            expect(created.status()).toBe(201);
            const { commentId } = await created.json();
            await page.goto('/');
            await expect(page).toHaveURL(/\/projects\/[0-9a-f-]+\/documents$/);
            const projectId = /\/projects\/([0-9a-f-]+)\/documents$/.exec(
                page.url(),
            )?.[1];
            expect(projectId).toBeTruthy();
            const siteUrl = '/projects/' + projectId + '/site-review';
            await page.goto(siteUrl);
            const capture = page.locator(
                '[data-comment-id="' + commentId + '"]',
            );
            await expect(capture).toBeVisible();
            await capture
                .getByLabel('Reply to this feedback')
                .fill('Before attaching.');
            await page.route(
                '**/site-review/comments/*/reply',
                async (route) => {
                    await route.fetch();
                    await route.abort('failed');
                },
                { times: 1 },
            );
            await capture.getByRole('button', { name: 'Post reply' }).click();
            await expect(capture.getByRole('alert')).toBeVisible();
            await expect(
                capture.getByLabel('Reply to this feedback'),
            ).toHaveValue('Before attaching.');
            await capture.getByRole('button', { name: 'Post reply' }).click();
            await expect(
                capture.locator('[data-site-review-reply]'),
            ).toHaveCount(1);
            await expect(
                capture.locator('[data-site-review-reply]'),
            ).toContainText('Before attaching.');
            await capture
                .getByRole('link', { name: 'Create card and attach' })
                .click();
            const title = 'Shared capture ' + surface + ' ' + RUN;
            await page.getByLabel('Title', { exact: true }).fill(title);
            await page
                .getByLabel('Column', { exact: true })
                .selectOption({ label: 'Backlog' });
            await page
                .getByRole('button', { name: 'Create card', exact: true })
                .click();
            await expect(
                page.getByRole('heading', { name: title, exact: true }),
            ).toBeVisible();
            const cardUrl = new URL(page.url()).pathname;
            const boardUrl = '/projects/' + projectId + '/board';
            if (surface === 'drawer') {
                await page.goto(boardUrl);
                await page
                    .locator('.lp-board-card__title[href="' + cardUrl + '"]')
                    .click();
            }
            await page
                .getByRole('tab', { name: 'Conversation', exact: true })
                .click();
            const conversation = page.locator(
                '#card-panel-conversation [data-site-feedback="' +
                    commentId +
                    '"]',
            );
            await expect(
                conversation.locator('[data-site-review-reply]'),
            ).toHaveCount(1);
            await expect(conversation).toContainText('Before attaching.');
            await expect(conversation).toContainText('Site review widget');
            await conversation
                .getByLabel('Reply to this feedback')
                .fill('From the card.');
            await page
                .getByRole('tab', { name: 'Feedback', exact: true })
                .click();
            const feedbackDraft = page
                .locator(
                    '#card-panel-feedback [data-site-feedback="' +
                        commentId +
                        '"]',
                )
                .getByLabel('Reply to this feedback');
            await feedbackDraft.fill('A separate feedback draft.');
            await page
                .getByRole('tab', { name: 'Conversation', exact: true })
                .click();
            if (surface === 'drawer') {
                const submissionId = await conversation
                    .locator('input[name$="[submissionId]"]')
                    .inputValue();
                await page.keyboard.press('Escape');
                await page
                    .getByRole('link', { name: 'Workshop', exact: true })
                    .click();
                await expect(page).toHaveURL('/projects/' + projectId);
                await expect(page.locator('html')).not.toHaveAttribute(
                    'data-turbo-preview',
                );
                await page
                    .locator('.lp-sidebar')
                    .getByRole('link', { name: 'Board', exact: true })
                    .click();
                await page
                    .locator('.lp-board-card__title[href="' + cardUrl + '"]')
                    .click();
                await page
                    .getByRole('tab', { name: 'Conversation', exact: true })
                    .click();
                await expect(
                    conversation.getByLabel('Reply to this feedback'),
                ).toHaveValue('From the card.');
                await expect(
                    conversation.locator('input[name$="[submissionId]"]'),
                ).toHaveValue(submissionId);
            }
            await page.route(
                '**/board/feedback/*/conversation/reply',
                (route) =>
                    route.fulfill({
                        status: 503,
                        contentType: 'text/html',
                        body: 'Service unavailable',
                    }),
                { times: 1 },
            );
            await conversation
                .getByRole('button', { name: 'Post reply' })
                .click();
            await expect(conversation.getByRole('alert')).toBeVisible();
            await expect(
                conversation.getByLabel('Reply to this feedback'),
            ).toHaveValue('From the card.');
            let releaseSubmission!: () => void;
            let submissionHeld!: () => void;
            const submissionReleased = new Promise<void>((resolve) => {
                releaseSubmission = resolve;
            });
            const submissionInFlight = new Promise<void>((resolve) => {
                submissionHeld = resolve;
            });
            // Hold the request itself. Replaying the 302 with route.fulfill()
            // makes the browser issue a redirect hop that Playwright never
            // offers to a handler, and `times: 1` disables interception
            // milliseconds before it arrives. CI saw that hop abort.
            const replyRoute = '**/board/feedback/*/conversation/reply';
            await page.route(replyRoute, async (route) => {
                submissionHeld();
                await submissionReleased;
                await route.continue();
            });
            await conversation
                .getByRole('button', { name: 'Post reply' })
                .click();
            await submissionInFlight;
            await conversation
                .getByLabel('Reply to this feedback')
                .fill('A new draft during confirmation.');
            releaseSubmission();
            await expect(
                conversation.locator('[data-site-review-reply]'),
            ).toHaveCount(2);
            await expect(conversation).toContainText('From the card.');
            await expect(
                conversation.getByLabel('Reply to this feedback'),
            ).toHaveValue('A new draft during confirmation.');
            await page.unroute(replyRoute);
            await conversation.getByLabel('Reply to this feedback').fill('');
            if (surface === 'drawer') {
                await page.keyboard.press('Escape');
                await page
                    .locator('.lp-board-card__title[href="' + cardUrl + '"]')
                    .click();
                await page
                    .getByRole('tab', { name: 'Conversation', exact: true })
                    .click();
                await expect(
                    conversation.getByLabel('Reply to this feedback'),
                ).toHaveValue('');
            }
            await expect(page).toHaveURL(
                surface === 'drawer' ? boardUrl : cardUrl + '?tab=conversation',
            );
            await page
                .getByRole('tab', { name: 'Feedback', exact: true })
                .click();
            const feedback = page.locator(
                '#card-panel-feedback [data-site-feedback="' + commentId + '"]',
            );
            await expect(
                feedback.locator('[data-site-review-reply]'),
            ).toHaveCount(2);
            await expect(feedback).toContainText('Before attaching.');
            await expect(feedback).toContainText('From the card.');
            await expect(feedbackDraft).toHaveValue(
                'A separate feedback draft.',
            );
            await feedbackDraft.fill('');
            await feedback
                .getByText('Captured elements', { exact: true })
                .click();
            await expect(feedback.locator('code')).toHaveText('.hero');
            await expect(
                feedback.getByRole('link', { name: 'Open source page' }),
            ).toHaveAttribute('href', 'https://example.com/captured-page');
            await feedback
                .getByRole('button', { name: 'Resolve', exact: true })
                .click();
            await expect(feedback.locator('.lp-status-chip')).toHaveText(
                'Resolved',
            );
            await expect(page).toHaveURL(
                surface === 'drawer' ? boardUrl : cardUrl + '?tab=feedback',
            );
            await page.goto(siteUrl + '#feedback-' + commentId);
            await expect(
                capture.locator('[data-site-review-reply]'),
            ).toHaveCount(2);
            await expect(capture).toContainText('From the card.');
            await expect(capture).toContainText('Original heading');
            await expect(capture).toHaveAttribute(
                'data-comment-status',
                'resolved',
            );
            await capture.locator('.lp-site-review-card-link').click();
            await expect(
                page.getByRole('tab', { name: 'Feedback', exact: true }),
            ).toHaveAttribute('aria-selected', 'true');
            await expect(feedback).toBeVisible();
            await expect(
                feedback.locator('[data-site-review-reply]'),
            ).toHaveCount(2);
            await page
                .getByRole('tab', { name: 'Conversation', exact: true })
                .click();
            await expect(conversation.locator('.lp-status-chip')).toHaveText(
                'Resolved',
            );
            await conversation
                .getByRole('button', { name: 'Reopen', exact: true })
                .click();
            await expect(conversation.locator('.lp-status-chip')).toHaveText(
                'Pending',
            );
            await page.goto(siteUrl + '#feedback-' + commentId);
            await expect(capture).toHaveAttribute(
                'data-comment-status',
                'pending',
            );
            await expect(
                capture.locator('[data-site-review-reply]'),
            ).toHaveCount(2);
        },
    );
}
