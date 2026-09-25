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
    // The board frame never reloads: the create and the save each place one card.
    const boardLoads: string[] = [];
    page.on('request', (request) => {
        if (request.headers()['turbo-frame'] === 'board-frame') {
            boardLoads.push(request.url());
        }
    });
    const nextColumn = page.locator(
        '.lp-board__column[data-column-slug="next"]',
    );
    const count = nextColumn.locator('.lp-board__column-count');
    const before = Number(await count.textContent());
    await drawer.getByLabel('Title', { exact: true }).fill(title);
    const create = drawer.getByRole('button', {
        name: 'Create card',
        exact: true,
    });
    await expect(create).toHaveAttribute(
        'data-turbo-submits-with',
        'Creating…',
    );
    await create.click();

    await expect(drawer).toHaveJSProperty('open', false);
    const card = nextColumn.locator(
        `.lp-board-card[data-card-title="${title}"]`,
    );
    await expect(card).toBeVisible();
    await expect(count).toHaveText(String(before + 1));
    await expect(page).toHaveURL(boardUrl);

    await card.getByRole('link').first().click();
    await expect(drawer).toHaveJSProperty('open', true);
    await drawer.getByRole('link', { name: 'Edit card', exact: true }).click();
    await expect(drawer.getByLabel('Title', { exact: true })).toHaveValue(
        title,
    );
    await drawer.getByLabel('Title', { exact: true }).fill(`${title} edited`);
    const save = drawer.getByRole('button', { name: 'Save card', exact: true });
    await expect(save).toHaveAttribute('data-turbo-submits-with', 'Saving…');
    await save.click();

    // The form stays open, says Saved for a moment, then offers Save again.
    const saved = drawer.getByRole('button', { name: 'Saved', exact: true });
    await expect(saved).toBeVisible();
    await expect(drawer.getByLabel('Title', { exact: true })).toHaveValue(
        `${title} edited`,
    );
    await expect(
        page.locator(`.lp-board-card[data-card-title="${title} edited"]`),
    ).toBeVisible();
    await expect(
        drawer.getByRole('button', { name: 'Save card', exact: true }),
    ).toBeVisible({ timeout: 6000 });
    expect(boardLoads).toEqual([]);

    await page.keyboard.press('Escape');
    await expect(drawer).toHaveJSProperty('open', false);
    await expect(page).toHaveURL(boardUrl);
});
