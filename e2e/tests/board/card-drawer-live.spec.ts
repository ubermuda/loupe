/**
 * Browser coverage for a card open in the drawer while another session
 * changes it: the drawer warns before a save overwrites newer text, shows a
 * card deleted elsewhere as deleted, and shows a move made elsewhere. The hub
 * delivers each change, so the run needs a Mercure hub the browser can reach.
 */

import { test, expect } from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eCardDrawerLive1!';

test('the drawer warns about a change made elsewhere, and shows a card deleted elsewhere', async ({
    browser,
    request,
}) => {
    // Two sessions, two saves and a delete, each a page visit.
    test.slow();

    const email = `e2e+drawerlive+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Drawer Live User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const editor = await signedInPage(browser, email, PASSWORD);
    const seeded = await editor.request.post('/dev/seed/document', {
        form: { title: 'E2E Drawer Live Project', markdown: '# Live' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = (await seeded.json()).projectId as string;
    const boardUrl = `/projects/${projectId}/board`;

    await editor.goto(`${boardUrl}/cards/new`);
    await editor.getByLabel('Title', { exact: true }).fill('Shared card');
    // The board draws no Backlog, where a new card lands by default.
    await editor.getByLabel('Column').selectOption({ label: 'Next' });
    await editor
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        editor.getByRole('heading', { name: 'Shared card', exact: true }),
    ).toBeVisible({ timeout: 15_000 });
    const cardUrl = new URL(editor.url()).pathname;

    await editor.goto(boardUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(editor.locator('[data-board-live-connected]')).toHaveCount(1);
    await editor
        .locator('.lp-board-card[data-card-title="Shared card"]')
        .getByRole('link')
        .first()
        .click();
    const drawer = editor.locator('dialog.lp-card-drawer-overlay');
    await drawer.getByRole('link', { name: 'Edit card', exact: true }).click();
    const body = drawer.getByLabel('Description', { exact: true });
    await body.fill(`Mine ${RUN}`);

    const other = await signedInPage(browser, email, PASSWORD);
    await other.goto(`${cardUrl}/edit`);
    await other
        .getByLabel('Description', { exact: true })
        .fill(`Theirs ${RUN}`);
    await other.getByRole('button', { name: 'Save card', exact: true }).click();
    await expect(
        other.getByRole('heading', { name: 'Shared card', exact: true }),
    ).toBeVisible({ timeout: 15_000 });

    const notice = drawer.getByRole('alert').filter({
        hasText: 'This card changed since you opened it.',
    });
    await expect(notice).toBeVisible();
    await expect(
        notice.getByRole('link', { name: 'View the latest version' }),
    ).toHaveAttribute('href', cardUrl);
    await expect(body).toHaveValue(`Mine ${RUN}`);

    // A plain save still asks, and keeps the text typed.
    const refused = editor.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === `${cardUrl}/edit`,
    );
    await drawer
        .getByRole('button', { name: 'Save card', exact: true })
        .click();
    expect((await refused).status()).toBe(422);
    await expect(notice).toBeVisible();
    await expect(body).toHaveValue(`Mine ${RUN}`);
    await notice.getByRole('button', { name: 'Save anyway' }).click();
    await expect(
        drawer.getByRole('button', { name: 'Saved', exact: true }),
    ).toBeVisible();
    await expect(notice).toBeHidden();

    await other.goto(cardUrl);
    await expect(other.locator('main')).toContainText(`Mine ${RUN}`);
    await other.getByRole('button', { name: 'Delete card' }).click();
    await other.getByRole('button', { name: 'Delete it' }).click();
    await expect(other).toHaveURL(new RegExp(`${boardUrl}$`));

    await expect(drawer.getByText('This card was deleted.')).toBeVisible();
    await expect(drawer.getByRole('button', { name: /^Save/ })).toHaveCount(0);
    await expect(
        editor.locator('.lp-board-card[data-card-title="Shared card"]'),
    ).toHaveCount(0);

    await editor.context().close();
    await other.context().close();
});

test('the open drawer shows a move made elsewhere, on the tab the reader had open', async ({
    browser,
    request,
}) => {
    test.slow();

    const email = `e2e+drawermove+${RUN}@example.com`;
    const registered = await request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Drawer Move User', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);

    const reader = await signedInPage(browser, email, PASSWORD);
    const seeded = await reader.request.post('/dev/seed/document', {
        form: { title: 'E2E Drawer Move Project', markdown: '# Move' },
    });
    expect(seeded.status()).toBe(201);
    const projectId = (await seeded.json()).projectId as string;
    const boardUrl = `/projects/${projectId}/board`;

    await reader.goto(`${boardUrl}/cards/new`);
    await reader.getByLabel('Title', { exact: true }).fill('Moving card');
    await reader.getByLabel('Column').selectOption({ label: 'Next' });
    await reader
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        reader.getByRole('heading', { name: 'Moving card', exact: true }),
    ).toBeVisible({ timeout: 15_000 });
    const cardUrl = new URL(reader.url()).pathname;

    await reader.goto(boardUrl);
    await expect(reader.locator('[data-board-live-connected]')).toHaveCount(1);
    await reader
        .locator('.lp-board-card[data-card-title="Moving card"]')
        .getByRole('link')
        .first()
        .click();
    const drawer = reader.locator('dialog.lp-card-drawer-overlay');
    const identity = drawer.locator('.lp-card-drawer__identity');
    await expect(identity).toContainText('Next');
    await drawer.getByRole('tab', { name: 'History', exact: true }).click();

    const other = await signedInPage(browser, email, PASSWORD);
    await other.goto(cardUrl);
    await other.getByRole('tab', { name: 'Details', exact: true }).click();
    await other
        .locator('.lp-card-move__form select[name$="[column]"]')
        .selectOption({ label: 'In progress' });
    await expect(other).toHaveURL(new RegExp(`${boardUrl}$`));

    // The update waits for the hub, a debounce and a fetch of the card.
    await expect(identity).toContainText('In progress', { timeout: 15000 });
    await expect(
        drawer.getByRole('tab', { name: 'History', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(drawer.locator('#card-panel-history')).toBeVisible();

    await reader.context().close();
    await other.context().close();
});
