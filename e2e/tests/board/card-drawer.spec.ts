import { expect, type Page } from '@playwright/test';
import {
    createTest,
    signWidgetIn,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';

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

test('site review opens a linked card in the drawer on its Feedback tab', async ({
    page,
}) => {
    // Before the capture: the harness call that finds the project also resets it.
    const siteUrl = `/projects/${await projectId(page)}/site-review`;
    const harness = await page.request.get(
        '/dev/site-review-harness?email=' + encodeURIComponent(EMAIL),
    );
    expect(harness.ok()).toBeTruthy();
    const harnessProject = /data-project="([^"]+)"/.exec(
        await harness.text(),
    )?.[1];
    expect(harnessProject).toBeTruthy();
    // The page carries no credential now, so the capture is written with the
    // grant the widget's own sign-in produces.
    const { accessToken } = await signWidgetIn(page, harnessProject!);
    const body = `Drawer capture ${RUN}`;
    const created = await page.request.post('/api/site-review/comments', {
        headers: { Authorization: 'Bearer ' + accessToken },
        data: {
            body,
            url: 'https://example.com/drawer-page',
            anchors: [{ selector: '.hero', text: 'Heading' }],
        },
    });
    expect(created.status()).toBe(201);
    const { commentId } = await created.json();

    const listItem = page.locator(
        `button.lp-feedback-list__item[data-master-detail-id="feedback-${commentId}"]`,
    );
    await page.goto(siteUrl);
    await listItem.click();
    const capture = page.locator(`[data-comment-id="${commentId}"]`);
    await capture.getByRole('link', { name: 'Create card and attach' }).click();
    const title = `Linked from review ${RUN}`;
    await page.getByLabel('Title', { exact: true }).fill(title);
    await page
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();

    await page.goto(siteUrl);
    await listItem.click();
    await page
        .locator(`#feedback-${commentId} footer.lp-feedback-detail__actions`)
        .getByRole('link', { name: /^Open card #/ })
        .click();

    const drawer = page.locator('dialog.lp-card-drawer-overlay');
    await expect(
        drawer.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();
    await expect(
        drawer.getByRole('tab', { name: 'Feedback', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(drawer.locator('#card-panel-feedback')).toContainText(body);
    expect(new URL(page.url()).pathname).toBe(siteUrl);
});
