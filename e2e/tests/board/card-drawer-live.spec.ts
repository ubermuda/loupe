/**
 * Browser coverage for a card edited in the drawer while another session
 * changes it: the drawer warns before a save overwrites newer text, and shows
 * a card deleted elsewhere as deleted. The hub delivers both changes, so the
 * run needs a Mercure hub the browser can reach.
 */

import { test, expect, type APIRequestContext } from '@playwright/test';
import { signedInPage } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eCardDrawerLive1!';

async function setFlag(
    request: APIRequestContext,
    name: string,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name, enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

test.afterAll(async ({ request }) => {
    await setFlag(request, 'board.enabled', false);
});

test('the drawer warns about a change made elsewhere, and shows a card deleted elsewhere', async ({
    browser,
    request,
}) => {
    // Two sessions, two saves and a delete, each a page visit.
    test.slow();
    await setFlag(request, 'board.enabled', true);
    await setFlag(request, 'live_updates.enabled', true);

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
    await editor
        .getByRole('button', { name: 'Create card', exact: true })
        .click();
    await expect(
        editor.getByRole('heading', { name: 'Shared card', exact: true }),
    ).toBeVisible();
    const cardUrl = new URL(editor.url()).pathname;

    await editor.goto(boardUrl);
    // The hub keeps no history, so a change made before this connects is lost.
    await expect(editor.locator('[data-board-refresh-connected]')).toHaveCount(
        1,
    );
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
    ).toBeVisible();

    const notice = drawer.getByRole('alert').filter({
        hasText: 'This card changed since you opened it.',
    });
    await expect(notice).toBeVisible();
    await expect(
        notice.getByRole('link', { name: 'View the latest version' }),
    ).toHaveAttribute('href', cardUrl);
    await expect(body).toHaveValue(`Mine ${RUN}`);

    // A plain save still asks, and keeps the text typed.
    await drawer
        .getByRole('button', { name: 'Save card', exact: true })
        .click();
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
