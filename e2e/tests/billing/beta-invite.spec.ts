/**
 * An admin makes a beta link, and a visitor signs up with it while the
 * registration cap is full. The account gets a comp and the beta mark.
 *
 * It closes the global `registration.cap`, so it runs in its own project,
 * serialized after the waitlist. The cap is restored in afterAll.
 */

import { expect, type Page } from '@playwright/test';
import { ADMIN, setRegistrationCap } from '../admin-helpers';
import { createTest } from '../fixtures';
import { submitRedirectingForm } from '../helpers';

const test = createTest(ADMIN);
const RUN = Date.now();

// Any cap of 1 or more is full, because the admin account already exists.
const CLOSED_CAP = 1;
const OPEN_CAP = 0;

test.describe.serial('beta invite links', () => {
    const note = `e2e beta ${RUN}`;
    const testerEmail = `e2e-beta-tester-${RUN}@example.com`;
    let guest: Page;
    let link = '';
    let capClosed = false;

    test.beforeAll(async ({ browser }) => {
        guest = await browser.newPage({
            storageState: { cookies: [], origins: [] },
        });
    });

    test.afterAll(async ({ browser, workerStorageState }) => {
        await guest.close();
        if (capClosed) {
            const admin = await browser.newPage({
                storageState: workerStorageState,
            });
            await setRegistrationCap(admin, OPEN_CAP);
            await admin.close();
        }
    });

    test('an admin makes a link and reads it from the page', async ({
        page: admin,
    }) => {
        await admin.goto('/admin/beta-invites');
        await admin.getByLabel('Note', { exact: true }).fill(note);
        await admin
            .getByRole('button', { name: 'Create link', exact: true })
            .click();

        const issued = admin.getByTestId('beta-invite-link');
        await expect(issued).toContainText('/beta/');
        link = (await issued.textContent())?.trim() ?? '';

        await expect(
            admin
                .locator('tr[data-beta-invite-id]', { hasText: note })
                .getByTestId('beta-invite-state'),
        ).toHaveText('Unused');
    });

    test('a visitor signs up with the link while the cap is full', async ({
        page: admin,
    }) => {
        await setRegistrationCap(admin, CLOSED_CAP);
        capClosed = true;

        await guest.goto('/register');
        await expect(guest).toHaveURL(/\/waitlist$/);

        await guest.goto(link);
        await expect(guest).toHaveURL(/\/beta\/[0-9a-f]{64}$/);
        await submitRedirectingForm(
            guest,
            guest.getByRole('button', { name: 'Continue to sign up' }),
            link,
        );
        await expect(guest).toHaveURL(/\/register$/);
        await guest.getByLabel('Email').fill(testerEmail);
        await guest.getByLabel('Display name').fill('Beta Tester');
        await guest.getByLabel('Password').fill('e2e_password_123');
        await guest.getByLabel('I agree to').check();
        await submitRedirectingForm(
            guest,
            guest.getByRole('button', { name: 'Create account' }),
            '/register',
        );
        await expect(guest).toHaveURL('/register/check-email');
    });

    test('the account has a comp and the beta mark', async ({
        page: admin,
    }) => {
        await admin.goto('/admin/beta-invites');
        await expect(
            admin
                .locator('tr[data-beta-invite-id]', { hasText: note })
                .getByTestId('beta-invite-state'),
        ).toContainText(`Used by ${testerEmail}`);

        await admin.goto(`/admin/users?q=${encodeURIComponent(testerEmail)}`);
        const row = admin.locator('turbo-frame#users-table tr[data-user-id]', {
            hasText: testerEmail,
        });
        await expect(row).toHaveCount(1);
        await row.getByRole('link', { name: 'Manage', exact: true }).click();
        await expect(admin).toHaveURL(/\/admin\/users\/[0-9a-f-]{36}/);

        await expect(admin.getByTestId('comp-status')).toHaveText('Comped');
        await expect(admin.getByTestId('beta-panel')).toContainText(
            'Beta tester since',
        );
        await expect(admin.getByTestId('beta-panel')).toContainText(note);
    });
});
