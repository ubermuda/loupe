import { expect, type Route } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-project-search@example.com',
    password: 'e2e_password_123',
});

test('project search opens matching documents and pages', async ({ page }) => {
    const query = `quartz${Date.now()}`;
    const title = `Searchable document ${query}`;
    const response = await page.request.post('/dev/seed/document', {
        form: { title, markdown: query },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId, documentId } = await response.json();
    const searchUrl = `/projects/${projectId}/search`;
    await page.goto(`/projects/${projectId}`);
    await page
        .getByRole('link', { name: 'Search project', exact: true })
        .click();
    const field = page.getByRole('searchbox', {
        name: 'Search pages, cards, and documents',
        exact: true,
    });
    await field.fill(query);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('#project-search-results')).not.toHaveAttribute(
        'busy',
        '',
    );
    const result = page.locator('[data-search-kind="document"]');
    await expect(result).toBeVisible();
    await expect(result).toHaveCount(1);
    await expect(result).toContainText(title);
    await expect(result).toHaveAttribute(
        'href',
        `/projects/${projectId}/documents/${documentId}/review`,
    );
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(390);
    await expect
        .poll(() =>
            result.evaluate(
                (element) => element.scrollWidth - element.clientWidth,
            ),
        )
        .toBeLessThanOrEqual(1);
    await result.scrollIntoViewIfNeeded();
    await page.screenshot({
        path: '/tmp/loupe-search-390-text200.png',
        fullPage: true,
        animations: 'disabled',
    });
    await result.click();
    await expect(page).toHaveURL(
        new RegExp(`/documents/${documentId}/review$`),
    );
    await expect(
        page.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();
    await page
        .getByRole('link', { name: 'Search project', exact: true })
        .click();
    await expect(
        page.getByRole('dialog', { name: 'Search project', exact: true }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(
        page.getByRole('dialog', { name: 'Search project', exact: true }),
    ).toBeHidden();
    await page.goto(searchUrl);
    await field.fill('Activity');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('[data-search-kind="page"]')).toHaveCount(1);
    await page.locator('[data-search-kind="page"]').click();
    await expect(
        page.getByRole('heading', { name: 'Project activity', exact: true }),
    ).toBeVisible();
    await page.goto(searchUrl);
    await field.fill(`missing${query}`);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.getByRole('status')).toHaveText(
        'No matching pages, cards, or documents.',
    );
});

test('search shortcuts preserve page drafts and recover from a failed request', async ({
    page,
}) => {
    const query = `sapphire${Date.now()}`;
    const response = await page.request.post('/dev/seed/document', {
        form: { title: query, markdown: query },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId, documentId } = await response.json();
    const editUrl = `/projects/${projectId}/edit`;
    await page.goto(editUrl);
    const name = page.getByLabel('Project name', { exact: true });
    await name.fill('Unsaved project draft');
    await page.keyboard.press('Control+k');
    const dialog = page.getByRole('dialog', {
        name: 'Search project',
        exact: true,
    });
    const field = dialog.getByRole('searchbox');
    await expect(dialog).toBeVisible();
    await expect(field).toBeFocused();
    await name.evaluate((element) => element.focus());
    await expect(field).toBeFocused();
    await field.fill(query);
    await expect(dialog.locator('[data-search-kind="document"]')).toHaveCount(
        1,
    );
    await expect(page).toHaveURL(new RegExp(`${editUrl}$`));
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(name).toHaveValue('Unsaved project draft');
    await expect(name).toBeFocused();

    const searchPattern = new RegExp(`/projects/${projectId}/search(?:\\?|$)`);
    let pendingRequest: (route: Route) => void;
    const requested = new Promise<Route>((resolve) => {
        pendingRequest = resolve;
    });
    await page.route(searchPattern, (route) => pendingRequest(route));
    await page.keyboard.press('Meta+k');
    await expect(dialog).toBeVisible();
    const failedRequest = await requested;
    await expect(dialog.getByRole('status')).toHaveText('Searching…');
    await failedRequest.fulfill({ status: 503, body: 'Unavailable' });
    await expect(dialog.getByRole('alert')).toHaveText(
        'Search is unavailable. Press Search to retry.',
    );
    await expect(field).toHaveValue(query);
    await page.unroute(searchPattern);
    await dialog.getByRole('button', { name: 'Search', exact: true }).click();
    const result = dialog.locator('[data-search-kind="document"]');
    await expect(result).toBeVisible();
    await field.focus();
    await page.keyboard.press('Tab');
    await expect(
        dialog.getByRole('button', { name: 'Search', exact: true }),
    ).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(result).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(
        new RegExp(`/documents/${documentId}/review$`),
    );
    await expect(
        page.getByRole('heading', { name: query, exact: true }),
    ).toBeVisible();
});

test('search keeps a working full-page fallback without JavaScript', async ({
    page,
    browser,
    context,
}) => {
    const query = `topaz${Date.now()}`;
    const response = await page.request.post('/dev/seed/document', {
        form: { title: query, markdown: query },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId } = await response.json();
    const fallbackContext = await browser.newContext({
        storageState: await context.storageState(),
        ignoreHTTPSErrors: true,
        javaScriptEnabled: false,
    });
    try {
        const fallback = await fallbackContext.newPage();
        await fallback.goto(
            `${new URL(response.url()).origin}/projects/${projectId}`,
        );
        await fallback
            .getByRole('link', { name: 'Search project', exact: true })
            .click();
        await fallback
            .getByRole('searchbox', {
                name: 'Search pages, cards, and documents',
                exact: true,
            })
            .fill(query);
        await fallback
            .getByRole('button', { name: 'Search', exact: true })
            .click();
        await expect(
            fallback.locator('[data-search-kind="document"]'),
        ).toContainText(query);
        await expect(
            fallback.getByRole('heading', {
                name: 'Search project',
                exact: true,
            }),
        ).toBeVisible();
    } finally {
        await fallbackContext.close();
    }
});
