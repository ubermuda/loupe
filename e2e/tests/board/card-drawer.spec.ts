import { expect, type Page } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const EMAIL = 'e2e-card-drawer@example.com';
const PASSWORD = 'E2eCardDrawer1!';
const RUN = Date.now();
const test = createTest({
    email: EMAIL,
    password: PASSWORD,
    name: 'Card Drawer Reviewer',
});

test.beforeEach(async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    const flag = await page.request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: 1 },
    });
    expect(flag.ok()).toBeTruthy();
});

// The flag is global, so it goes back to its shipped value, on, for later specs.
test.afterAll(async ({ request }) => {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: 1 },
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

test('a column adds and edits a card in the drawer, and the board follows', async ({
    page,
}) => {
    const boardUrl = `/projects/${await projectId(page)}/board`;
    const title = `Drawer card ${RUN}`;
    await page.goto(boardUrl);

    await page
        .locator(
            '.lp-board__column[data-column-slug="next"] .lp-board__add-card',
        )
        .click();
    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    await expect(drawer).toHaveJSProperty('open', true);
    await expect(drawer.getByLabel('Column', { exact: true })).toHaveValue(
        (await page
            .locator('.lp-board__column[data-column-slug="next"]')
            .getAttribute('data-column-id')) ?? '',
    );
    await drawer.getByLabel('Title', { exact: true }).fill(title);
    await drawer
        .getByRole('button', { name: 'Create card', exact: true })
        .click();

    await expect(
        drawer.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();
    await expect(page).toHaveURL(boardUrl);
    await expect(
        page.locator(
            `.lp-board__column[data-column-slug="next"] .lp-board-card[data-card-title="${title}"]`,
        ),
    ).toBeVisible();

    await drawer.getByRole('link', { name: 'Edit card', exact: true }).click();
    await expect(drawer.getByLabel('Title', { exact: true })).toHaveValue(
        title,
    );
    await expect(drawer).toHaveJSProperty('open', true);
    await drawer.getByLabel('Title', { exact: true }).fill(`${title} edited`);
    await drawer
        .getByRole('button', { name: 'Save card', exact: true })
        .click();

    await expect(
        drawer.getByRole('heading', { name: `${title} edited`, exact: true }),
    ).toBeVisible();
    await expect(
        drawer.getByRole('tab', { name: 'Overview', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(
        page.locator(`.lp-board-card[data-card-title="${title} edited"]`),
    ).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(drawer).toHaveJSProperty('open', false);
    await expect(page).toHaveURL(boardUrl);
});
