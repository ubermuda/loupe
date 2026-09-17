import { expect, type Route } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-activity-feed@example.com',
    password: 'e2e_password_123',
});

test('pause keeps the feed fixed while real events arrive and resume reconciles them', async ({
    page,
    context,
}) => {
    const projectName = `activity-${Date.now()}`;
    await page.goto('/projects');
    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: /new project/i })
        .click();
    await page.getByLabel('Project name').fill(projectName);
    await page.getByRole('button', { name: 'Add project' }).click();
    const editLink = page.getByRole('link', { name: `Edit ${projectName}` });
    await expect(editLink).toBeVisible();
    const editUrl = await editLink.getAttribute('href');
    await expect(editLink).toHaveAttribute('href', /\/projects\/[^/]+\/edit$/);
    const projectId = editUrl!.match(/projects\/([^/]+)/)![1];
    const activityUrl = `/projects/${projectId}/activity`;
    await page.goto(activityUrl);
    const feed = page.locator('[data-activity-filter-project-value]');
    await expect(feed).toHaveAttribute('data-activity-state', 'listening');
    await page.getByRole('button', { name: 'Pause feed' }).click();
    await expect(feed).toHaveAttribute('data-activity-state', 'paused');

    const editor = await context.newPage();
    await editor.goto(editUrl!);
    await editor
        .getByLabel('Project name', { exact: true })
        .fill(`${projectName}-renamed`);
    await editor
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        editor.getByRole('link', { name: `Edit ${projectName}-renamed` }),
    ).toBeVisible();
    await expect(page.locator('[data-activity-event-id]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Resume feed' }).click();
    await expect(page.locator('[data-activity-event-id]')).toHaveCount(1);
    await expect(page.locator('[data-activity-event-id]')).toContainText(
        'project.renamed',
    );
    await expect(feed).toHaveAttribute('data-activity-state', 'listening');

    await page.getByRole('button', { name: 'Pause feed' }).click();
    await page.route(`**${activityUrl}`, (route) =>
        route.fulfill({ status: 503, body: 'Unavailable' }),
    );
    await page.getByRole('button', { name: 'Resume feed' }).click();
    await expect(feed).toHaveAttribute('data-activity-state', 'stale');
    await expect(page.locator('[data-activity-event-id]')).toHaveCount(1);
    await page.unroute(`**${activityUrl}`);
    await page.getByRole('button', { name: 'Pause feed' }).click();
    await page.getByRole('button', { name: 'Resume feed' }).click();
    await expect(feed).toHaveAttribute('data-activity-state', 'listening');
    await expect(page.locator('[data-activity-event-id]')).toHaveCount(1);
    await editor.close();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(390);
    await page.screenshot({
        path: '/tmp/loupe-activity-390.png',
        fullPage: true,
        animations: 'disabled',
    });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await expect
        .poll(() =>
            page
                .locator('.lp-activity-row__body')
                .evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
        )
        .toBeLessThanOrEqual(1);
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(390);
    await expect
        .poll(() =>
            feed
                .locator('[data-activity-filter-target="status"]')
                .evaluate((element) => element.getBoundingClientRect().right),
        )
        .toBeLessThanOrEqual(390);
    await page.screenshot({
        path: '/tmp/loupe-activity-390-text200.png',
        fullPage: true,
        animations: 'disabled',
    });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(editUrl!);
    await page
        .getByLabel('Project name', { exact: true })
        .fill('Unsaved local draft');
    const trigger = page.getByRole('link', {
        name: 'Open project activity',
        exact: true,
    });
    await trigger.click();
    const drawer = page.getByRole('dialog', { name: 'Recent activity' });
    await expect(drawer).toBeVisible();
    await expect(drawer.locator('[data-activity-event-id]')).toHaveCount(1);
    await expect(
        drawer.getByRole('button', { name: 'Close activity' }),
    ).toBeFocused();
    await page
        .getByLabel('Project name', { exact: true })
        .evaluate((element) => element.focus());
    await expect(
        drawer.getByRole('button', { name: 'Close activity' }),
    ).toBeFocused();
    await page.screenshot({
        path: '/tmp/loupe-activity-drawer-1440.png',
        animations: 'disabled',
    });
    await expect(page).toHaveURL(new RegExp(`${editUrl!}$`));
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(page.getByLabel('Project name', { exact: true })).toHaveValue(
        'Unsaved local draft',
    );
    let pendingRequest: (route: Route) => void;
    const requested = new Promise<Route>((resolve) => {
        pendingRequest = resolve;
    });
    await page.route(`**${activityUrl}/recent`, (route) =>
        pendingRequest(route),
    );
    await trigger.click();
    const failedRequest = await requested;
    await expect(drawer.getByRole('status')).toHaveText(
        'Loading recent activity…',
    );
    await failedRequest.fulfill({ status: 503, body: 'Unavailable' });
    await expect(drawer.getByRole('alert')).toContainText(
        'Recent activity is unavailable',
    );
    await drawer.getByRole('button', { name: 'Close activity' }).click();
    await expect(drawer).toBeHidden();
    await page.unroute(`**${activityUrl}/recent`);
    await trigger.click();
    await expect(drawer.locator('[data-activity-event-id]')).toBeVisible();
    await drawer
        .getByRole('link', { name: 'Open activity', exact: true })
        .click();
    await expect(page).toHaveURL(new RegExp(`${activityUrl}$`));
    await expect(
        page.getByRole('heading', { name: 'Project activity', exact: true }),
    ).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await trigger.click();
    await expect(drawer.locator('[data-activity-event-id]')).toBeVisible();
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await expect
        .poll(() =>
            drawer
                .locator('.lp-activity-drawer__body')
                .evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
        )
        .toBeLessThanOrEqual(1);
    await drawer.locator('[data-activity-event-id]').scrollIntoViewIfNeeded();
    await page.screenshot({
        path: '/tmp/loupe-activity-drawer-390-text200.png',
        animations: 'disabled',
    });
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(trigger).toBeFocused();
});
