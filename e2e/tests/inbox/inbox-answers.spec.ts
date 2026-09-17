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

for (const workspace of [
    {
        route: 'inbox',
        action: 'View activity',
        role: 'link',
        destination: 'activity',
    },
    {
        route: 'site-review',
        action: 'Widget setup',
        role: 'link',
        destination: 'connect',
    },
    {
        route: 'projects',
        action: 'New project',
        role: 'button',
        destination: null,
    },
] as const) {
    test(`${workspace.route} header keeps its action reachable with enlarged text`, async ({
        page,
        inbox,
    }, testInfo) => {
        const projectPath = inbox.inboxUrl.replace(/\/inbox$/, '');
        await page.goto(
            workspace.route === 'projects'
                ? '/projects'
                : `${projectPath}/${workspace.route}`,
        );
        const header = page.locator('.lp-page-header');
        const action = header.getByRole(workspace.role, {
            name: workspace.action,
            exact: true,
        });
        await expect(action).toHaveCount(1);
        for (const fontSize of ['100%', '200%']) {
            await page.evaluate((size) => {
                document.documentElement.style.fontSize = size;
            }, fontSize);
            for (const width of [1440, 1150, 950, 780, 390]) {
                await page.setViewportSize({ width, height: 1000 });
                await page.screenshot({
                    path: testInfo.outputPath(
                        `${workspace.route}-${width}-${fontSize}.png`,
                    ),
                    animations: 'disabled',
                });
                await expect(action).toBeInViewport({ ratio: 1 });
                const copyBounds = await header
                    .locator('.lp-page-header__copy')
                    .boundingBox();
                const descriptionBounds = await header
                    .locator('.lp-workspace-desc')
                    .boundingBox();
                expect(copyBounds).not.toBeNull();
                expect(descriptionBounds).not.toBeNull();
                expect(
                    copyBounds!.y +
                        copyBounds!.height -
                        descriptionBounds!.y -
                        descriptionBounds!.height,
                ).toBeLessThanOrEqual(1);
                expect(
                    await header.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                ).toBeLessThanOrEqual(1);
                expect(
                    await action.evaluate((element) => {
                        const bounds = element.getBoundingClientRect();
                        const range = document.createRange();
                        range.selectNodeContents(element);
                        const content = range.getBoundingClientRect();
                        return Math.max(
                            bounds.left - content.left,
                            content.right - bounds.right,
                        );
                    }),
                ).toBeLessThanOrEqual(1);
            }
        }
        await action.focus();
        await expect(action).toBeFocused();
        await action.press('Enter');
        if (workspace.destination === null) {
            await expect(page.locator('#new-project-panel')).toBeVisible();
            await expect(action).toHaveAttribute('aria-expanded', 'true');
            await expect(
                page
                    .locator('#new-project-panel')
                    .getByLabel('Project name', { exact: true }),
            ).toBeFocused();
        } else {
            await expect(page).toHaveURL(
                `${projectPath}/${workspace.destination}`,
            );
        }
    });
}

test('the project inbox filter row keeps its unboxed layout', async ({
    page,
}) => {
    const filters = page.locator('.lp-needs-you .lp-list-filters');
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(filters).toBeVisible();
        await expect(filters).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
        await expect(filters).toHaveCSS('padding', '0px');
        await expect(filters).toHaveCSS('margin-bottom', '20px');
    }
});

test('inbox search keeps Clear reachable before and after filtering', async ({
    page,
}) => {
    const search = page.locator('#inbox-search');
    const clear = page.locator('.lp-filter-clear');
    const filters = page.locator('.lp-list-filters');
    async function verifyLayout(): Promise<void> {
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await expect(search).toBeVisible();
            await expect(clear).toBeVisible();
            const searchBox = await search.boundingBox();
            const clearBox = await clear.boundingBox();
            const filtersBox = await filters.boundingBox();
            expect(searchBox).not.toBeNull();
            expect(clearBox).not.toBeNull();
            expect(filtersBox).not.toBeNull();
            expect(searchBox!.width).toBe(270);
            expect(clearBox!.x - searchBox!.x - searchBox!.width).toBe(8);
            expect(clearBox!.y + clearBox!.height / 2).toBe(
                searchBox!.y + searchBox!.height / 2,
            );
            expect(clearBox!.x + clearBox!.width).toBeLessThanOrEqual(
                filtersBox!.x + filtersBox!.width,
            );
            for (const count of await page.locator('.lp-filter-count').all()) {
                const countBox = await count.boundingBox();
                expect(countBox).not.toBeNull();
                expect(countBox!.x + countBox!.width).toBeLessThanOrEqual(
                    filtersBox!.x + filtersBox!.width,
                );
                const overlapWidth =
                    Math.min(
                        countBox!.x + countBox!.width,
                        clearBox!.x + clearBox!.width,
                    ) - Math.max(countBox!.x, clearBox!.x);
                const overlapHeight =
                    Math.min(
                        countBox!.y + countBox!.height,
                        clearBox!.y + clearBox!.height,
                    ) - Math.max(countBox!.y, clearBox!.y);
                expect(
                    Math.min(overlapWidth, overlapHeight),
                ).toBeLessThanOrEqual(0);
            }
        }
    }
    await verifyLayout();
    await search.fill('unmatched-inbox-query');
    await expect(page.locator('[data-inbox-search-empty]')).toBeVisible();
    await expect(page.locator('.lp-filter-count')).toHaveText(
        'No item matches',
    );
    await verifyLayout();
    await clear.click();
    await expect(search).toHaveValue('');
    await expect(
        page.locator('[data-inbox-section="open-asks"]'),
    ).toBeVisible();
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

    // The to-do still blocks the ask, so the answer can still change, here to text alone.
    await question.getByRole('button', { name: 'Clear the option' }).click();
    await question.getByLabel('Your answer').fill('Text alone this time.');
    await question.getByRole('button', { name: 'Change the answer' }).click();
    await expect(question.locator('[data-inbox-choices]')).toHaveCount(0);
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'Text alone this time.',
    );

    await question.getByText('CSV', { exact: true }).click();
    await question.getByLabel('Your answer').fill('An unsent correction.');
    await question.locator('.lp-inbox-decline__summary').click();
    await question
        .getByLabel('A note for the agent (optional)')
        .fill('An unsent decline note.');

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

    const recovery = question.locator('[data-inbox-draft-kind="answer"]');
    await expect(recovery).toBeVisible();
    await expect(recovery).toContainText('An unsent correction.');
    await expect(recovery.locator('li')).toHaveText(['CSV']);
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'Text alone this time.',
    );
    await recovery.getByRole('button', { name: 'Discard draft' }).click();
    await expect(recovery).toBeHidden();
    await expect(question.getByRole('heading')).toBeFocused();
    const declineRecovery = question.locator(
        '[data-inbox-draft-kind="decline"]',
    );
    await expect(declineRecovery).toBeVisible();
    await expect(declineRecovery).toContainText('An unsent decline note.');
    await declineRecovery
        .getByRole('button', { name: 'Discard draft' })
        .click();
    await expect(declineRecovery).toBeHidden();
    await expect(question.getByRole('heading')).toBeFocused();

    await page.reload();
    await expect(question.locator('[data-inbox-response]')).toContainText(
        'Text alone this time.',
    );
    // The decline closed the last blocking item, so the ask closed and the answer is final.
    await expect(question.locator('[data-inbox-editable="no"]')).toBeVisible();
});
