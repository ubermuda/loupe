import { expect } from '@playwright/test';
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
});
