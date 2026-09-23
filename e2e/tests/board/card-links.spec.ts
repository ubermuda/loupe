import { expect, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const EMAIL = 'e2e-card-links@example.com';
const PASSWORD = 'E2eCardLinks1!';
const RUN = Date.now();
const test = createTest({
    email: EMAIL,
    password: PASSWORD,
    name: 'Card Links Reviewer',
});

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    const flag = await page.request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: 1 },
    });
    expect(flag.ok()).toBeTruthy();
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: 0 },
    });
    expect(response.ok()).toBeTruthy();
});

/** The site-review harness gives the account its project, so it runs first. */
async function projectId(page: Page): Promise<string> {
    const harness = await page.request.get(
        '/dev/site-review-harness?email=' + encodeURIComponent(EMAIL),
    );
    expect(harness.ok()).toBeTruthy();
    await page.goto('/');
    await expect(page).toHaveURL(/\/projects\/[0-9a-f-]+\/documents$/);
    const id = /\/projects\/([0-9a-f-]+)\/documents$/.exec(page.url())?.[1];
    expect(id).toBeTruthy();

    return id ?? '';
}

/** Creates a card through the form and returns the path of its page. */
async function createCard(
    page: Page,
    project: string,
    title: string,
): Promise<string> {
    await page.goto(`/projects/${project}/board/cards/new`);
    await page.getByLabel('Title', { exact: true }).fill(title);
    await page
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();

    return new URL(page.url()).pathname;
}

test('a link picked on the edit form shows on the other card as it reads it', async ({
    page,
}) => {
    const project = await projectId(page);
    const blocker = `Ship the schema ${RUN}`;
    const blocked = `Ship the page ${RUN}`;
    const blockerPath = await createCard(page, project, blocker);
    const blockedPath = await createCard(page, project, blocked);

    await page.goto(`${blockerPath}/edit`);
    await page
        .getByRole('button', { name: 'Add a linked card', exact: true })
        .click();
    const row = page.locator('[data-form-collection-row]');
    await expect(row).toHaveCount(1);
    await row.locator('.ts-control').click();
    await row.locator('.ts-control input').fill(`page ${RUN}`);
    await row.locator('.ts-dropdown .option', { hasText: blocked }).click();
    await row.getByLabel('Link', { exact: true }).selectOption('blocks');
    await page.getByRole('button', { name: 'Save card', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: blocker, exact: true }),
    ).toBeVisible();

    await page.goto(blockedPath);
    const linked = page.locator('[data-linked-cards] [data-linked-card]');
    await expect(linked).toHaveCount(1);
    await expect(linked).toContainText('Blocked by');
    await expect(linked).toContainText(blocker);
});
