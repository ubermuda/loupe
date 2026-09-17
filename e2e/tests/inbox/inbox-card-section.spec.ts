/**
 * Browser coverage for the inbox section of a card page: the owner answers a
 * linked question there and lands back on the same card, with the answer shown
 * in the section.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eInboxCard1!';

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

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Inbox Card User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome');
}

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

// Both flags are global, so they go back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setFlag(request, 'inbox.enabled', false);
    await setFlag(request, 'board.enabled', false);
});

test('card and inbox show linked PRs with readable status and safe actions', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setFlag(page.request, 'inbox.enabled', true);
    await setFlag(page.request, 'board.enabled', true);
    await registerAndLogin(page, `e2e+inbox+pulls+${RUN}@example.com`);
    const project = await page.request.post('/dev/seed/document', {
        form: { title: 'Linked PR project', markdown: '# Review' },
    });
    expect(project.status()).toBe(201);
    const { projectId } = await project.json();
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Review linked pull requests');
    await page.getByLabel('Column').selectOption({ label: 'Backlog' });
    await page
        .locator('textarea[name="create_card_form[pullRequestUrls]"]')
        .fill('https://github.com/example/app/pull/42\njavascript:alert(1)');
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(
        page.getByRole('heading', { name: 'Review linked pull requests' }),
    ).toBeVisible();
    const cardUrl = new URL(page.url()).pathname;
    const cardId = cardUrl.split('/').pop() ?? '';
    const seeded = await page.request.post('/dev/seed/inbox', {
        form: { cardId },
    });
    expect(seeded.status()).toBe(201);
    const { questionNumber } = await seeded.json();

    for (const path of [
        cardUrl,
        `/projects/${projectId}/inbox#inbox-item-${questionNumber}`,
    ]) {
        await page.goto(path);
        const rows = page.locator('[data-linked-pull-request]');
        await expect(rows).toHaveCount(2);
        await expect(rows.first()).toContainText('example/app #42');
        await expect(rows.first().locator('.lp-tag')).toHaveText(
            'Not reported',
        );
        await expect(rows.first().getByRole('link')).toHaveAttribute(
            'href',
            'https://github.com/example/app/pull/42',
        );
        await expect(rows.last().locator('.lp-tag')).toHaveText('Unavailable');
        await expect(rows.last()).toContainText('Not a web address');
        await expect(rows.last().getByRole('link')).toHaveCount(0);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 900 });
            for (const badge of await rows.locator('.lp-tag').all()) {
                await expect(badge).toBeVisible();
                await expect(badge).toHaveCSS('white-space', 'nowrap');
                expect(
                    await badge.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                ).toBe(0);
                const bounds = await badge.evaluate((element) => ({
                    badgeRight: element.getBoundingClientRect().right,
                    cellRight:
                        element.parentElement!.getBoundingClientRect().right,
                }));
                expect(bounds.badgeRight).toBeLessThanOrEqual(bounds.cellRight);
            }
        }
    }
});

test('an inbox card link reveals its matching request in Conversation', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setFlag(page.request, 'inbox.enabled', true);
    await setFlag(page.request, 'board.enabled', true);
    await registerAndLogin(page, `e2e+inbox+target+${RUN}@example.com`);
    const project = await page.request.post('/dev/seed/document', {
        form: { title: 'Request navigation project', markdown: '# Requests' },
    });
    expect(project.status()).toBe(201);
    const { projectId } = await project.json();
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title').fill('Find the matching request');
    await page.getByLabel('Column').selectOption({ label: 'Backlog' });
    await page.getByRole('button', { name: 'Create card' }).click();
    await expect(
        page.getByRole('heading', { name: 'Find the matching request' }),
    ).toBeVisible();
    const cardUrl = new URL(page.url()).pathname;
    const cardId = cardUrl.split('/').pop() ?? '';
    for (let index = 0; index < 3; index++) {
        const earlier = await page.request.post('/dev/seed/inbox', {
            form: { cardId },
        });
        expect(earlier.status()).toBe(201);
    }
    const seeded = await page.request.post('/dev/seed/inbox', {
        form: { cardId },
    });
    expect(seeded.status()).toBe(201);
    const { questionNumber } = await seeded.json();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(
            `/projects/${projectId}/inbox#inbox-item-${questionNumber}`,
        );
        await page
            .locator(`#inbox-item-${questionNumber}`)
            .getByRole('link', {
                name: 'Open Find the matching request conversation',
            })
            .click();
        await expect(page).toHaveURL(
            `${cardUrl}?tab=conversation&inboxItem=${questionNumber}#inbox-item-${questionNumber}`,
        );
        await expect(
            page.getByRole('tab', { name: 'Conversation' }),
        ).toHaveAttribute('aria-selected', 'true');
        await expect(
            page.locator('[data-inbox-linked="card"] [data-inbox-item]'),
        ).toHaveCount(4);
        const target = page.locator(
            `[data-inbox-linked="card"] #inbox-item-${questionNumber}`,
        );
        await expect(target.getByRole('heading')).toBeInViewport();
        await page.reload();
        await expect(
            page.getByRole('tab', { name: 'Conversation' }),
        ).toHaveAttribute('aria-selected', 'true');
        await expect(target.getByRole('heading')).toBeInViewport();
        await page.getByRole('tab', { name: 'Overview' }).click();
        await page.reload();
        await expect(
            page.getByRole('tab', { name: 'Overview' }),
        ).toHaveAttribute('aria-selected', 'true');
        await expect(target).toBeHidden();
    }
});

for (const target of ['document', 'pull-request']) {
    test(`a ${target} review from the drawer shares its result with the inbox`, async ({
        page,
    }) => {
        await suppressToolbar(page);
        await suppressWidget(page);
        await setFlag(page.request, 'inbox.enabled', true);
        await setFlag(page.request, 'board.enabled', true);
        await registerAndLogin(
            page,
            `e2e+inbox+review+${target}+${RUN}@example.com`,
        );
        const seededDocument = await page.request.post('/dev/seed/document', {
            form: {
                title: 'Shared review design',
                markdown: '# Review this design',
            },
        });
        expect(seededDocument.status()).toBe(201);
        const { projectId, documentId } = await seededDocument.json();
        await page.goto(`/projects/${projectId}/board/cards/new`);
        await page.getByLabel('Title').fill('Shared review card');
        await page.getByLabel('Column').selectOption({ label: 'Backlog' });
        await page
            .locator('textarea[name="create_card_form[pullRequestUrls]"]')
            .fill('https://github.com/example/app/pull/72');
        await page.getByRole('button', { name: 'Create card' }).click();
        await expect(
            page.getByRole('heading', { name: 'Shared review card' }),
        ).toBeVisible();
        const cardId = new URL(page.url()).pathname.split('/').pop() ?? '';
        const pullRequest = page.locator('[data-linked-pull-request]').first();
        await expect(pullRequest).toHaveAttribute(
            'data-linked-pull-request',
            /^[0-9a-f-]{36}$/,
        );
        const pullRequestId = await pullRequest.getAttribute(
            'data-linked-pull-request',
        );
        const seededReview = await page.request.post('/dev/seed/inbox-review', {
            form: {
                cardId,
                ...(target === 'document'
                    ? { documentId }
                    : { pullRequestId: pullRequestId ?? '' }),
            },
        });
        expect(seededReview.status()).toBe(201);
        const { number } = await seededReview.json();
        const boardUrl = `/projects/${projectId}/board`;
        await page.goto(boardUrl);
        await page.getByRole('link', { name: /Shared review card/ }).click();
        const drawer = page.getByRole('dialog', { name: 'Card details' });
        await expect(drawer).toBeVisible();
        await drawer.getByRole('tab', { name: 'Conversation' }).click();
        const item = drawer.locator(`#inbox-item-${number}`);
        const form = item.locator('form').filter({
            has: page.getByRole('button', {
                name: 'Submit review',
                exact: true,
            }),
        });
        await form.getByLabel('Request changes', { exact: true }).check();
        await form
            .getByRole('button', { name: 'Submit review', exact: true })
            .click();
        await expect(item).toContainText(
            'Explain the changes you request in a review note.',
        );
        await expect(drawer).toBeVisible();
        await expect(page).toHaveURL(boardUrl);
        await form
            .locator('textarea')
            .fill('Explain retries before implementation.');
        await form
            .getByRole('button', { name: 'Submit review', exact: true })
            .click();
        await expect(item.locator('[data-inbox-review-verdict]')).toHaveText(
            'Changes requested',
        );
        await expect(item.locator('[data-inbox-response]')).toContainText(
            'Explain retries before implementation.',
        );
        await expect(drawer).toBeVisible();
        await expect(page).toHaveURL(boardUrl);
        await page.goto(`/projects/${projectId}/inbox#inbox-item-${number}`);
        const completed = page.locator(`#inbox-item-${number}`);
        await expect(
            completed.locator('[data-inbox-review-verdict]'),
        ).toHaveText('Changes requested');
        await expect(completed.locator('[data-inbox-response]')).toContainText(
            'Explain retries before implementation.',
        );
        await expect(
            completed.getByRole('button', { name: 'Submit review' }),
        ).toHaveCount(0);
        if (target === 'document') {
            await page.goto(
                `/projects/${projectId}/documents/${documentId}/review`,
            );
            await page.locator('.lp-verdict-bar__undo button').click();
            await expect(page.locator('.lp-flash')).toContainText(
                'Your verdict has been withdrawn.',
            );
            await page.goto(
                `/projects/${projectId}/inbox#inbox-item-${number}`,
            );
            await expect(
                completed.locator('[data-inbox-review-withdrawal]'),
            ).toContainText('withdrew this verdict');
            await expect(
                completed.locator('[data-inbox-review-verdict]'),
            ).toHaveText('Changes requested');
        }
    });
}

for (const surface of ['page', 'drawer']) {
    test(`the owner answers a linked question from the card ${surface} and stays there`, async ({
        page,
    }) => {
        await suppressToolbar(page);
        await suppressWidget(page);
        await setFlag(page.request, 'inbox.enabled', true);
        await setFlag(page.request, 'board.enabled', true);
        await registerAndLogin(
            page,
            `e2e+inbox+card+${surface}+${RUN}@example.com`,
        );

        // The document seed creates the harness project the card and the ask share.
        const project = await page.request.post('/dev/seed/document', {
            form: { title: 'E2E Inbox Card Project', markdown: '# Inbox' },
        });
        expect(project.status()).toBe(201);
        const { projectId } = await project.json();

        await page.goto(`/projects/${projectId}/board/cards/new`);
        await page.getByLabel('Title').fill('Ship the export');
        await page.getByLabel('Column').selectOption({ label: 'Backlog' });
        await page.getByRole('button', { name: 'Create card' }).click();
        await expect(
            page.getByRole('heading', { name: 'Ship the export' }),
        ).toBeVisible();
        const cardUrl = new URL(page.url()).pathname;
        const cardId = cardUrl.split('/').pop() ?? '';

        const seeded = await page.request.post('/dev/seed/inbox', {
            form: { cardId },
        });
        expect(seeded.status()).toBe(201);
        const { questionNumber } = await seeded.json();

        const boardUrl = `/projects/${projectId}/board`;
        const drawer = page.getByRole('dialog', { name: 'Card details' });
        if (surface === 'drawer') {
            await page.goto(boardUrl);
            await page.getByRole('link', { name: /Ship the export/ }).click();
            await expect(drawer).toBeVisible();
        } else {
            await page.goto(cardUrl);
        }
        await page.getByRole('tab', { name: 'Conversation' }).click();
        const section = page.locator('[data-inbox-linked="card"]');
        const question = section.locator(`#inbox-item-${questionNumber}`);
        await expect(
            section.locator('[data-inbox-linked-context]'),
        ).toContainText('export');

        await question.getByRole('button', { name: 'Answer' }).click();
        await expect(question).toContainText(
            'Pick an option or write an answer.',
        );
        if (surface === 'drawer') {
            await expect(drawer).toBeVisible();
            await expect(page).toHaveURL(boardUrl);
        }
        await expect(
            page.getByRole('tab', { name: 'Conversation' }),
        ).toHaveAttribute('aria-selected', 'true');

        await question.getByText('CSV', { exact: true }).click();
        await question
            .getByLabel('Your answer')
            .fill('The importer reads CSV.');
        await question.getByRole('button', { name: 'Answer' }).click();

        await expect(page.locator('.lp-flash')).toContainText(
            `Item ${questionNumber} is answered.`,
        );
        await expect(page).toHaveURL(
            surface === 'drawer' ? boardUrl : `${cardUrl}?tab=conversation`,
        );
        if (surface === 'drawer') {
            await expect(drawer).toBeVisible();
        }
        await expect(
            page.getByRole('tab', { name: 'Conversation' }),
        ).toHaveAttribute('aria-selected', 'true');
        await expect(
            page.getByRole('heading', { name: 'Ship the export' }),
        ).toBeVisible();
        // An answered question is closed, so it moves below the open items.
        await expect(
            section.locator(
                `[data-inbox-linked-closed] #inbox-item-${questionNumber}`,
            ),
        ).toBeVisible();
        await expect(question.locator('[data-inbox-choices] li')).toHaveText([
            'CSV',
        ]);
        await expect(question.locator('[data-inbox-response]')).toContainText(
            'The importer reads CSV.',
        );
        const replyDraft = question.getByLabel('Reply to this thread');
        await replyDraft.fill('Keep the existing column order.');
        await page.route(
            '**/inbox/items/*/reply*',
            async (route) => {
                await route.fetch();
                await route.abort('failed');
            },
            { times: 1 },
        );
        await question.getByRole('button', { name: 'Post reply' }).click();
        await expect(question.getByRole('alert')).toContainText(
            'Your reply could not be confirmed.',
        );
        await expect(replyDraft).toHaveValue('Keep the existing column order.');
        await question.getByRole('button', { name: 'Post reply' }).click();
        await expect(question.locator('[data-inbox-reply]')).toHaveCount(1);
        await expect(question.locator('[data-inbox-reply]')).toContainText(
            'Keep the existing column order.',
        );
        await expect(page).toHaveURL(
            surface === 'drawer' ? boardUrl : `${cardUrl}?tab=conversation`,
        );
        await question.locator('.lp-inbox-decline__summary').click();
        await question
            .getByLabel('A note for the agent (optional)')
            .fill('Wait for the new importer.');
        await question
            .getByRole('button', { name: 'Decline this item' })
            .click();
        await expect(page.locator('.lp-flash')).toContainText(
            `Item ${questionNumber} is declined.`,
        );
        await expect(question.locator('[data-inbox-response]')).toContainText(
            'Wait for the new importer.',
        );
        await expect(page).toHaveURL(
            surface === 'drawer' ? boardUrl : `${cardUrl}?tab=conversation`,
        );
        await expect(
            page.getByRole('tab', { name: 'Conversation' }),
        ).toHaveAttribute('aria-selected', 'true');
        if (surface === 'drawer') {
            await expect(drawer).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(drawer).toBeHidden();
            await expect(
                page.getByRole('link', { name: /Ship the export/ }),
            ).toBeFocused();
        }
        await page.goto(
            `/projects/${projectId}/inbox#inbox-item-${questionNumber}`,
        );
        const inboxReply = page.locator(
            `#inbox-item-${questionNumber} [data-inbox-reply]`,
        );
        await expect(inboxReply).toHaveCount(1);
        await expect(inboxReply).toContainText(
            'Keep the existing column order.',
        );
    });
}
