/**
 * Browser coverage for the project inbox: the owner answers a question by
 * picking an option, which the answer controller copies into the hidden field,
 * and declines a to-do with a note. Both are read back after the redirect.
 */

import {
    test as base,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eInboxAnswers1!';

async function setInboxFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'inbox.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Inbox User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome');
}

interface Inbox {
    inboxUrl: string;
    questionNumber: number;
    todoNumber: number;
}

const test = base.extend<{ inbox: Inbox }>({
    inbox: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);
            await setInboxFlag(page.request, true);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            await registerAndLogin(page, `e2e+inbox+${tag}+${RUN}@example.com`);

            const response = await page.request.post('/dev/seed/inbox');
            expect(response.status()).toBe(201);
            const seeded = await response.json();

            const inboxUrl = `/projects/${seeded.projectId}/inbox`;
            await page.goto(inboxUrl);

            await use({
                inboxUrl,
                questionNumber: seeded.questionNumber,
                todoNumber: seeded.todoNumber,
            });
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setInboxFlag(request, false);
});

test('the owner answers a question and declines a to-do', async ({
    page,
    inbox,
}) => {
    const sidebarPill = page.locator('[data-inbox-open-count]');
    await expect(sidebarPill).toHaveText('2');

    const question = page.locator(`#inbox-item-${inbox.questionNumber}`);
    await expect(question).toContainText(`item ${inbox.questionNumber}`);
    await question.getByText('CSV', { exact: true }).click();
    await question.getByLabel('Your answer').fill('The importer reads CSV.');
    await question.getByRole('button', { name: 'Answer' }).click();

    await expect(page.locator('.lp-flash')).toContainText(
        `Item ${inbox.questionNumber} is answered.`,
    );
    // The picked option only reaches the server through the answer controller.
    await expect(question.locator('[data-inbox-choices] li')).toHaveText([
        'CSV',
    ]);
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'The importer reads CSV.',
    );
    await expect(question.locator('[data-inbox-editable="yes"]')).toBeVisible();
    await expect(sidebarPill).toHaveText('1');

    // No ask has closed, so the answer can still change, here to text alone.
    await question.getByRole('button', { name: 'Clear the option' }).click();
    await question.getByLabel('Your answer').fill('Text alone this time.');
    await question.getByRole('button', { name: 'Change the answer' }).click();
    await expect(question.locator('[data-inbox-choices]')).toHaveCount(0);
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'Text alone this time.',
    );

    const todo = page.locator(`#inbox-item-${inbox.todoNumber}`);
    await todo.getByText('Decline', { exact: true }).click();
    await todo
        .getByLabel('A note for the agent (optional)')
        .fill('Someone else reviews this one.');
    await todo.getByRole('button', { name: 'Decline this item' }).click();

    await expect(page.locator('.lp-flash')).toContainText(
        `Item ${inbox.todoNumber} is declined.`,
    );
    await expect(todo.locator('[data-inbox-response]')).toContainText(
        'Someone else reviews this one.',
    );
    await expect(todo).toContainText('Declined');
    await expect(sidebarPill).toHaveCount(0);

    await page.reload();
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'Text alone this time.',
    );
});
