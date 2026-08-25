/**
 * End-to-end coverage of admin user management: an admin finds an account in
 * the list, opens its detail page, suspends it with a reason, and the account
 * holder is pinned to /account/suspended and shown that reason — then the
 * suspension is lifted and the account deleted through the typed-email
 * confirmation.
 *
 * `test.describe.serial()` because every test after the first depends on state
 * a prior one left behind: the victim account exists only after the first test
 * registers it, the filter assertions read the suspended state that test set,
 * and the last test deletes the account outright.
 */

import { expect, type Page } from '@playwright/test';
import { ADMIN } from '../admin-helpers';
import { createTest } from '../fixtures';
import { type Credentials, registerAndVerify } from '../helpers';

const test = createTest(ADMIN);
const RUN = Date.now();

const VICTIM_NAME = `Managed User ${RUN}`;
const victim: Credentials = {
    email: `e2e-managed-user-${RUN}@example.com`,
    password: 'e2e_password_123',
    fullName: VICTIM_NAME,
};

// Run-unique so the suspension page can only be showing the reason THIS run
// typed into the admin form — a static label would also pass against a page
// that hardcodes one.
const SUSPENSION_REASON = `Repeatedly abusive comments (case ${RUN})`;

const ROWS = 'turbo-frame#users-table tr[data-user-id]';
const SEARCH = 'form[data-controller="autosearch"] input[name="q"]';
const STATE = 'form[data-controller="autosearch"] select[name="state"]';

/** The users list pre-filtered to the victim, with no debounce involved. */
function victimListUrl(): string {
    return `/admin/users?q=${encodeURIComponent(victim.email)}`;
}

/**
 * Opens the victim's detail page by following its row's Manage link, which is
 * how an operator reaches it and what makes the row's `returnTo` real.
 */
async function openVictimDetail(admin: Page): Promise<void> {
    // Server-rendered filter rather than the debounced search box: typing
    // leaves the row's `returnTo` (built from the request URI) racing the
    // debounce, and the Manage link would carry the unfiltered list. The
    // debounced search has its own test below.
    await admin.goto(victimListUrl());

    const row = admin.locator(ROWS, { hasText: victim.email });
    await expect(row).toHaveCount(1);
    await row.getByRole('link', { name: 'Manage', exact: true }).click();

    await expect(admin).toHaveURL(/\/admin\/users\/[0-9a-f-]{36}/);
    await expect(admin.locator('[data-testid="danger-zone"]')).toBeVisible();
}

test.describe.serial('admin user management', () => {
    let victimPage: Page;

    test.beforeAll(async ({ browser }) => {
        // Explicit blank storage state: browser.newPage() otherwise inherits
        // this file's storageState fixture, which createTest(ADMIN) overrides
        // to the admin session — the "victim" would silently be the admin.
        victimPage = await browser.newPage({
            storageState: { cookies: [], origins: [] },
        });
    });

    test.afterAll(async () => {
        await victimPage.close();
    });

    test('an admin suspends an account with a reason, and the account holder is pinned to the suspension page', async ({
        page: admin,
        request,
    }) => {
        // Registration polls Mailpit for up to 30s on its own, which is the
        // whole default budget before the admin half of this test even starts.
        test.setTimeout(90_000);

        await registerAndVerify(victimPage, request, victim);

        await openVictimDetail(admin);
        // The reason field lives in a closed <details>; its contents are out of
        // the accessibility tree until the summary is clicked.
        await admin.locator('[data-testid="suspend-disclosure"]').click();
        await admin.locator('#suspend-reason').fill(SUSPENSION_REASON);
        await admin
            .getByRole('button', { name: 'Suspend account', exact: true })
            .click();

        // The suspend form posts a returnTo of the current URL, so the redirect
        // lands back here — toHaveURL would resolve before the POST
        // round-trips. Wait on the summary, which renders only once suspended.
        // 15s because this page is still fetching a lazy controller and two
        // fonts after first paint; at 5s a slow runner never got there.
        await expect(
            admin.locator('[data-testid="suspension-details"]'),
        ).toContainText(SUSPENSION_REASON, { timeout: 15000 });
        await expect(admin.locator('[data-testid="user-status"]')).toHaveText(
            'Suspended',
            { timeout: 15000 },
        );

        // The account holder is now pinned: any safe navigation lands on the
        // explanation page, whatever they asked for.
        await victimPage.goto('/projects');
        await expect(victimPage).toHaveURL(/\/account\/suspended$/);
        await expect(
            victimPage.getByRole('heading', {
                name: 'Your account has been suspended',
            }),
        ).toBeVisible();
        await expect(victimPage.locator('.auth-error')).toContainText(
            'Reason given:',
        );
        await expect(victimPage.locator('.auth-error')).toContainText(
            SUSPENSION_REASON,
        );
    });

    test('the list filters narrow the table to the suspended account', async ({
        page: admin,
    }) => {
        await admin.goto('/admin/users');

        await admin.locator(SEARCH).fill(victim.email);
        // The frame's turbo-action="replace" rewrites the address bar only
        // once the debounced request has come back, so this proves the
        // narrowing below is the server's answer, not the first render.
        await expect(admin).toHaveURL(/[?&]q=e2e-managed-user/);
        await expect(admin.locator(ROWS)).toHaveCount(1);
        await expect(admin.locator(ROWS)).toContainText(victim.email);

        await admin.locator(STATE).selectOption('suspended');
        await expect(admin.locator(ROWS)).toHaveCount(1);
        await expect(admin.locator(ROWS)).toContainText(victim.email);

        // Narrowing to zero needs the positive companion: a row count of 0
        // would also pass if the frame had simply failed to load.
        await admin.locator(STATE).selectOption('active');
        await expect(
            admin.getByText('No users match these filters.'),
        ).toBeVisible();
        await expect(admin.locator(ROWS)).toHaveCount(0);
    });

    test("a row's Manage link opens the detail page as a full-page visit", async ({
        page: admin,
    }) => {
        await admin.goto('/admin/users');
        await admin.locator(SEARCH).fill(victim.email);
        await expect(admin.locator(ROWS)).toHaveCount(1);

        await admin
            .locator(ROWS, { hasText: victim.email })
            .getByRole('link', { name: 'Manage', exact: true })
            .click();

        await expect(admin).toHaveURL(/\/admin\/users\/[0-9a-f-]{36}/);
        // The rows live inside <turbo-frame id="users-table">, so without
        // data-turbo-frame="_top" the link resolves as a frame navigation: the
        // frame survives and shows "Content missing". The absence of the frame
        // is what proves the whole document was replaced — the negative text
        // assertion below would also pass if the click had done nothing.
        await expect(admin.locator('turbo-frame#users-table')).toHaveCount(0);
        await expect(admin.getByText('Content missing')).toHaveCount(0);
        await expect(
            admin.locator('[data-testid="danger-zone"]'),
        ).toBeVisible();
        await expect(
            admin.getByRole('heading', { name: VICTIM_NAME }),
        ).toBeVisible();
    });

    test('lifting the suspension restores access for the account holder', async ({
        page: admin,
    }) => {
        await openVictimDetail(admin);
        await admin
            .getByRole('button', { name: 'Lift suspension', exact: true })
            .click();

        // Same-URL redirect again: the suspend disclosure replacing the
        // unsuspend row is the signal that the POST landed.
        await expect(
            admin.locator('[data-testid="suspend-disclosure"]'),
        ).toBeVisible();
        await expect(admin.locator('[data-testid="user-status"]')).toHaveText(
            'Active',
        );

        await victimPage.goto('/projects');
        await expect(victimPage).toHaveURL('/projects');
    });

    test('deleting an account requires typing its exact email address', async ({
        page: admin,
    }) => {
        await openVictimDetail(admin);
        await admin
            .getByRole('button', { name: 'Delete account', exact: true })
            .click();

        // The dialog's contents are out of the accessibility tree until it is
        // opened, so this must follow the trigger click.
        const dialog = admin.locator('.admin-dialog-box');
        const confirmButton = dialog.getByRole('button', {
            name: 'I understand, delete this account',
        });
        await expect(confirmButton).toBeDisabled();

        const confirmInput = dialog.locator('#delete-confirm-email');
        await confirmInput.fill(victim.email.slice(0, -1));
        await expect(confirmButton).toBeDisabled();

        await confirmInput.fill(victim.email);
        await expect(confirmButton).toBeEnabled();
        await confirmButton.click();

        await expect(admin).toHaveURL(/\/admin\/users(\?|$)/);
        await expect(admin.locator('.admin-alert-success')).toContainText(
            'Account permanently deleted.',
        );

        // Asserted from a fresh filtered list rather than from wherever the
        // returnTo happened to land, so this proves the account is gone rather
        // than that one redirect kept its filter.
        await admin.goto(victimListUrl());
        await expect(
            admin.getByText('No users match these filters.'),
        ).toBeVisible();
        await expect(admin.locator(ROWS)).toHaveCount(0);
    });
});
