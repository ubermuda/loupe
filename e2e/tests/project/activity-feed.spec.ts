import { expect, type Page, type Route } from '@playwright/test';
import { createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-activity-events-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

// On a shared, loaded php-fpm one request in twenty stalled for up to 17s.
const NAVIGATION = { timeout: 30_000 };

/** A fresh project per test, so no test sees the events of another. */
async function seedProject(page: Page, name: string): Promise<string> {
    await page.goto('/projects');
    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: /new project/i })
        .click();
    await page.getByLabel('Project name').fill(name);
    await page.getByRole('button', { name: 'Add project' }).click();
    await expect(page.locator('.lp-sidebar__switcher-name')).toHaveText(
        name,
        NAVIGATION,
    );
    await page.goto('/projects');
    const editLink = page.getByRole('link', {
        name: `Edit ${name}`,
        exact: true,
    });
    await expect(editLink).toHaveAttribute('href', /\/projects\/[^/]+\/edit$/);

    return (await editLink.getAttribute('href'))!.match(
        /projects\/([^/]+)/,
    )![1];
}

/** Each rename records one project.renamed event, with the new slug in its data. */
async function rename(page: Page, projectId: string, name: string) {
    await page.goto(`/projects/${projectId}/edit`);
    await page.getByLabel('Project name', { exact: true }).fill(name);
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(
        page.getByRole('link', { name: `Edit ${name}`, exact: true }),
    ).toBeVisible(NAVIGATION);
}

/** A card moved from Backlog to Next records one board.card_moved event that links to the card. */
async function moveNewCard(page: Page, projectId: string, title: string) {
    await page.goto(`/projects/${projectId}/board/cards/new`);
    await page.getByLabel('Title', { exact: true }).fill(title);
    await page.getByLabel('Column').selectOption({ label: 'Backlog' });
    await page
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: title, exact: true }),
    ).toBeVisible(NAVIGATION);
    await page.getByRole('tab', { name: 'Details', exact: true }).click();
    await page.getByLabel('Column').selectOption({ label: 'Next' });
    await expect(page).toHaveURL(/\/board$/, NAVIGATION);
}

// Paging, the clamp and the page links are proven in ActivityPageTest.
test('the events list links, searches and filters through the URL', async ({
    page,
}) => {
    // About fifteen page loads, and one stall can take 17s of the 30s default.
    test.setTimeout(90_000);
    await suppressWidget(page);
    const run = Date.now().toString(36);
    const projectId = await seedProject(page, `Events ${run}`);
    await rename(page, projectId, `Events ${run} renamed`);
    const cardTitle = `Events card ${run}`;
    await moveNewCard(page, projectId, cardTitle);
    const activityUrl = `/projects/${projectId}/activity`;
    const rows = page.locator('[data-activity-event-id]');

    await page.goto(activityUrl);
    await expect(rows).toHaveCount(2);
    await expect(page.locator('turbo-frame#activity-count')).toHaveText(
        '2 events',
    );
    const moved = rows.filter({ hasText: 'Backlog → Next' });
    await expect(moved.locator('.lp-tag')).toHaveText('Board');
    await expect(moved.locator('.lp-status-chip')).toBeVisible();

    // The new slug is in the event data only, so this proves the search reads it.
    const search = page.getByRole('searchbox', { name: 'Search activity' });
    await search.fill(`events-${run}-renamed`);
    await expect(page).toHaveURL(
        new RegExp(`search=events-${run}-renamed`),
        NAVIGATION,
    );
    await expect(rows).toHaveCount(1);
    await expect(rows.first().locator('.lp-data-table__title')).toHaveText(
        'Project renamed',
    );
    // A rename links to no work, so its row opens nothing.
    await expect(page.locator('.lp-data-table__target')).toHaveCount(0);

    await page.getByRole('link', { name: 'Clear', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${activityUrl}$`), NAVIGATION);
    await expect(rows).toHaveCount(2);

    const family = page.getByRole('combobox', {
        name: 'Filter by event family',
    });
    await family.selectOption({ label: 'Needs you' });
    await expect(page).toHaveURL(/[?&]family=inbox(&|$)/, NAVIGATION);
    await expect(page.locator('[data-activity-filtered-empty]')).toHaveText(
        'No activity matches these filters.',
    );
    await expect(search).toBeVisible();

    await page
        .getByRole('combobox', { name: 'Filter by event family' })
        .selectOption({ label: 'Board' });
    await expect(page).toHaveURL(/[?&]family=board(&|$)/, NAVIGATION);
    await expect(rows).toHaveCount(1);

    await rows.first().getByRole('link').click();
    await expect(
        page.getByRole('heading', { name: cardTitle, exact: true }),
    ).toBeVisible(NAVIGATION);

    await page.goto(activityUrl);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth))
        .toBeLessThanOrEqual(390);
});

test('a project with no events shows the empty state and no filters', async ({
    page,
}) => {
    await suppressWidget(page);
    const projectId = await seedProject(page, `Quiet ${Date.now()}`);

    await page.goto(`/projects/${projectId}/activity`);
    await expect(page.locator('[data-activity-empty]')).toContainText(
        'No recorded activity',
    );
    await expect(page.locator('.lp-list-filters')).toHaveCount(0);
});

test('the bell drawer lists recent events and keeps the page in place', async ({
    page,
}) => {
    await suppressWidget(page);
    const projectId = await seedProject(page, `Drawer ${Date.now()}`);
    const editUrl = `/projects/${projectId}/edit`;
    const activityUrl = `/projects/${projectId}/activity`;
    await rename(page, projectId, `Drawer renamed ${Date.now()}`);

    await page.goto(editUrl);
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
    await expect(page).toHaveURL(new RegExp(`${editUrl}$`));
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
    await expect(page.locator('[data-activity-event-id]')).toHaveCount(1);
});
