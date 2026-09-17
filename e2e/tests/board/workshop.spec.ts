import { expect, type Route } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-workshop-records@example.com',
    password: 'e2e_password_123',
});

test.afterEach(async ({ page }) => {
    for (const name of ['board.enabled', 'inbox.enabled']) {
        const response = await page.request.post('/dev/e2e/feature-flag', {
            form: { name, enabled: 0 },
        });
        expect(response.ok()).toBeTruthy();
    }
});

test('workshop opens the matching request and keeps card details in its drawer', async ({
    page,
}) => {
    for (const name of ['board.enabled', 'inbox.enabled']) {
        const response = await page.request.post('/dev/e2e/feature-flag', {
            form: { name, enabled: 1 },
        });
        expect(response.ok()).toBeTruthy();
    }
    const first = await page.request.post('/dev/seed/inbox');
    expect(first.ok()).toBeTruthy();
    const { projectId, questionNumber } = await first.json();
    const second = await page.request.post('/dev/seed/inbox');
    expect(second.ok()).toBeTruthy();
    const workshopUrl = `/projects/${projectId}`;
    const title = `Workshop card ${Date.now()} ${'x'.repeat(90)}`;
    await page.goto(`${workshopUrl}/board/cards/new`);
    await page.getByLabel('Title', { exact: true }).fill(title);
    await page
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();
    await page.goto(workshopUrl);
    await page
        .locator(
            `[data-workshop-attention][href$="#inbox-item-${questionNumber}"]`,
        )
        .click();
    await expect(page.locator(`#inbox-item-${questionNumber}`)).toBeVisible();

    await page.goto(workshopUrl);
    const card = page
        .locator('[data-workshop-card]')
        .filter({ hasText: title });
    const cardUrl = (await card.getAttribute('href'))!;
    await page.route(`**${cardUrl}`, (route) =>
        route.fulfill({
            status: 503,
            contentType: 'text/html',
            body: 'Unavailable',
        }),
    );
    await card.click();
    const drawer = page.getByRole('dialog', {
        name: 'Card details',
        exact: true,
    });
    await expect(drawer).toBeVisible();
    await expect(drawer.getByRole('alert')).toHaveText(
        'The card could not load. Try again.',
    );
    await drawer
        .getByRole('button', { name: 'Close card', exact: true })
        .click();
    await expect(card).toBeFocused();
    await card.click();
    await expect(drawer.getByRole('alert')).toBeVisible();
    await page.unroute(`**${cardUrl}`);
    await drawer.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(
        drawer.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();
    await expect(
        drawer.getByRole('tab', { name: 'Overview', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(page).toHaveURL(new RegExp(`${workshopUrl}$`));
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(card).toBeFocused();
    for (const fail of [
        (route: Route) => route.abort('failed'),
        (route: Route) =>
            route.fulfill({
                status: 200,
                contentType: 'text/html',
                body: '<p>No frame</p>',
            }),
        (route: Route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: '{}',
            }),
    ]) {
        await page.route(`**${cardUrl}`, fail);
        await card.click();
        await expect(drawer.getByRole('alert')).toBeVisible();
        await expect(
            drawer.getByRole('button', { name: 'Retry', exact: true }),
        ).toBeFocused();
        await page.unroute(`**${cardUrl}`, fail);
        await drawer
            .getByRole('button', { name: 'Retry', exact: true })
            .click();
        await expect(
            drawer.getByRole('heading', { name: title, exact: true }),
        ).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(card).toBeFocused();
    }
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect
            .poll(() =>
                page.evaluate(() => document.documentElement.scrollWidth),
            )
            .toBeLessThanOrEqual(width);
        await expect
            .poll(() =>
                card.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            )
            .toBeLessThanOrEqual(1);
        await page.screenshot({
            path: `/tmp/loupe-workshop-${width}.png`,
            fullPage: true,
            animations: 'disabled',
        });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await card.scrollIntoViewIfNeeded();
    await page.screenshot({
        path: '/tmp/loupe-workshop-390-text200.png',
        fullPage: true,
        animations: 'disabled',
    });
    await expect(card.locator('.lp-workshop-work-card__badge')).toHaveText(
        'Backlog',
    );
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(390);
    await expect
        .poll(() =>
            card.evaluate(
                (element) => element.scrollWidth - element.clientWidth,
            ),
        )
        .toBeLessThanOrEqual(1);
    await card.click();
    await expect(
        drawer.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(card).toBeFocused();
});
