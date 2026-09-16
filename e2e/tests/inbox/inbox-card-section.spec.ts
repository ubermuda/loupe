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

test('the owner answers a linked question from the card page and stays there', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setFlag(page.request, 'inbox.enabled', true);
    await setFlag(page.request, 'board.enabled', true);
    await registerAndLogin(page, `e2e+inbox+card+${RUN}@example.com`);

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

    await page.goto(cardUrl);
    await page.getByRole('tab', { name: 'Conversation' }).click();
    const section = page.locator('[data-inbox-linked="card"]');
    const question = section.locator(`#inbox-item-${questionNumber}`);
    await expect(section.locator('[data-inbox-linked-context]')).toContainText(
        'export',
    );

    await question.getByText('CSV', { exact: true }).click();
    await question.getByLabel('Your answer').fill('The importer reads CSV.');
    await question.getByRole('button', { name: 'Answer' }).click();

    await expect(page.locator('.lp-flash')).toContainText(
        `Item ${questionNumber} is answered.`,
    );
    await expect(page).toHaveURL(`${cardUrl}?tab=conversation`);
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
});
