/**
 * End-to-end coverage of the registration cap + waitlist loop: an admin
 * closes registration by setting the `registration.cap` feature flag, a
 * guest is diverted to /waitlist, an admin invites through all three admin
 * mechanisms (per-entry, bulk-selected, oldest-N), and the invited guest
 * registers through the invite link and ends up shown as Converted.
 *
 * Split into named, `test.describe.serial()` behaviours rather than
 * independent tests: every test after the first depends on state a prior
 * test left behind (the cap being closed, or a specific waitlist entry
 * existing), and the cap itself is process-wide — genuinely independent
 * tests would race each other flipping it. Serial execution keeps that
 * dependency explicit instead of hidden inside one long test body, while
 * still giving each behaviour its own pass/fail line and its own default
 * 30s budget (see the timeout note on the redemption test below).
 *
 * Runs in its own Playwright project, serialized after the main suite
 * (`dependencies: ['chromium']` in playwright.config.ts) because it mutates
 * the global `registration.cap` flag that every other spec's registration
 * and OAuth-signup paths depend on being open. The cap is always restored
 * to open in `afterAll`, even on failure, so a crash here cannot wedge every
 * other run.
 */

import { expect, type Page } from '@playwright/test';
import { ADMIN, setRegistrationCap } from '../admin-helpers';
import { createTest } from '../fixtures';
import { extractLink, getEmailWithSubject } from '../helpers';

const test = createTest(ADMIN);
const RUN = Date.now();

// Any value >= 1 closes the gate: RegistrationGate.isOpen() compares the
// user count against the cap, and by the time this project runs (it depends
// on the chromium project) the app always has at least the admin user. A
// fixed low bound avoids needing to read the exact user count from the UI.
const CLOSED_CAP = 1;
const OPEN_CAP = 0;

/**
 * Waits for a waitlist entry's status badge to reach the expected text by
 * repeatedly reloading the admin list from scratch (sorted newest-first).
 * All three admin invite actions redirect to the bare, unsorted list URL,
 * and the per-entry button's form lives inside the page's turbo-frame — its
 * submission updates that frame in place with no observable URL change — so
 * re-navigating and polling is the one signal that works uniformly across
 * all three mechanisms, and stays correct no matter how many older entries
 * have accumulated in this worktree's dev database across previous runs
 * (newest-first sort always keeps this run's rows on page one).
 */
async function expectWaitlistStatus(
    admin: Page,
    email: string,
    status: string,
): Promise<void> {
    await expect(async () => {
        await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
        await expect(
            admin
                .locator('tr[data-waitlist-entry-id]', { hasText: email })
                .getByText(status, { exact: true }),
        ).toBeVisible();
    }).toPass({ timeout: 15000 });
}

test.describe.serial('registration cap and waitlist', () => {
    const perEntryEmail = `e2e-waitlist-perentry-${RUN}@example.com`;
    const selectedEmail = `e2e-waitlist-selected-${RUN}@example.com`;
    const oldestEmail = `e2e-waitlist-oldest-${RUN}@example.com`;

    // Shared across every test in this file: a single guest browsing session
    // (so the three waitlist joins in one test are visible to the admin
    // invite tests that follow), and whether this run actually closed the
    // cap (so afterAll knows whether restoring it is safe — see its comment).
    let guest: Page;
    let capClosed = false;

    test.beforeAll(async ({ browser }) => {
        // Explicit blank storageState: browser.newPage() otherwise inherits
        // the current worker's storageState fixture — which createTest(ADMIN)
        // above overrides to the admin's authenticated session — so without
        // this the "guest" page silently starts out logged in as admin. See
        // project-e2e's "Guest test scoping" convention.
        guest = await browser.newPage({
            storageState: { cookies: [], origins: [] },
        });
    });

    test.afterAll(async ({ browser, workerStorageState }) => {
        await guest.close();
        // Only restore the cap when a test in this file actually closed it: a
        // failure while closing it would otherwise throw AGAIN from this
        // restore and mask the original error. page (the admin fixture) is
        // test-scoped and unavailable in afterAll, so open a fresh admin page
        // from the worker's already-authenticated storage state instead.
        if (capClosed) {
            const admin = await browser.newPage({
                storageState: workerStorageState,
            });
            await setRegistrationCap(admin, OPEN_CAP);
            await admin.close();
        }
    });

    test('a closed cap diverts a guest from registration to the waitlist', async ({
        page: admin,
    }) => {
        await setRegistrationCap(admin, CLOSED_CAP);
        capClosed = true;

        // Guest hits /register while the gate is closed → diverted to the waitlist.
        await guest.goto('/register');
        await expect(guest).toHaveURL(/\/waitlist$/);
        await expect(guest.getByText('Registration is full')).toBeVisible();
    });

    test('a guest can join the waitlist while the cap is closed', async () => {
        // Join with all three addresses — each admin invite mechanism tested
        // below gets its own entry.
        for (const email of [perEntryEmail, selectedEmail, oldestEmail]) {
            await guest.goto('/waitlist');
            await guest.getByLabel('Email address').fill(email);
            await guest
                .getByRole('button', { name: 'Join the waitlist' })
                .click();
            await expect(guest.getByText("You're on the list")).toBeVisible();
        }
    });

    test('an unknown invite token falls back to the closed-cap waitlist page', async () => {
        // An invalid/unknown invite token must fall back to the same
        // closed-cap behaviour as no token at all — never quietly reopen
        // registration, and never show the waitlist "joined" confirmation
        // for someone who never actually joined. findOneByValidInviteToken()
        // returns null the same way for an invalid token as it would for an
        // expired one, so this exercises that fallback path; an actually
        // expired (but well-formed) token is covered at the PHPUnit level
        // instead, since manufacturing a real 7-day-old row is out of reach
        // for e2e without direct database manipulation.
        await guest.goto('/register?invite=not-a-real-invite-token');
        await expect(guest).toHaveURL(/\/waitlist$/);
        await expect(guest.getByText('Registration is full')).toBeVisible();
    });

    test("admin invites a single entry from its own row's Invite button", async ({
        page: admin,
    }) => {
        await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
        const perEntryRow = admin.locator('tr[data-waitlist-entry-id]', {
            hasText: perEntryEmail,
        });
        await perEntryRow
            .getByRole('button', { name: 'Invite', exact: true })
            .click();
        await expectWaitlistStatus(admin, perEntryEmail, 'Invited');
    });

    test('admin invites an entry via checkbox selection and Invite selected', async ({
        page: admin,
    }) => {
        await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
        const selectedRow = admin.locator('tr[data-waitlist-entry-id]', {
            hasText: selectedEmail,
        });
        await selectedRow.getByRole('checkbox').check();
        await admin
            .getByRole('button', { name: 'Invite selected', exact: true })
            .click();
        await expectWaitlistStatus(admin, selectedEmail, 'Invited');
    });

    test('admin invites the remaining entries via Invite oldest', async ({
        page: admin,
    }) => {
        // Sweeps the rest (including the third entry) via "Invite oldest",
        // using the max allowed count so it isn't thrown off by any older,
        // still-uninvited debris left behind by a previous interrupted run.
        await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
        await admin.getByLabel('Invite oldest').fill('100');
        await admin
            .getByRole('button', { name: 'Invite oldest', exact: true })
            .click();
        await expectWaitlistStatus(admin, oldestEmail, 'Invited');
    });

    test('redeeming an invite converts the waitlist entry into an account, shown as Converted', async ({
        page: admin,
        request,
    }) => {
        // Redeem the per-entry invite: fetch its link from Mailpit, follow it
        // (the session-exchange redirect strips the token from the URL),
        // register with the SAME address that was waitlisted — redemption is
        // bound to that address, and any other one is rejected — verify, and
        // land on the first-run wizard like any other fresh account.
        const invite = await getEmailWithSubject(
            request,
            perEntryEmail,
            'Your Loupe invite is here',
        );
        const inviteUrl = extractLink(
            invite.body,
            /https?:\/\/[^\s"<]+\/register\?invite=[^\s"<]+/,
        );

        await guest.goto(inviteUrl);
        // The session-exchange redirect must have landed on the plain
        // register form (registration open for this guest via the invite),
        // not back on the waitlist.
        await expect(guest).toHaveURL(/\/register$/);
        await expect(guest.getByLabel('Full name')).toBeVisible();

        await guest.getByLabel('Full name').fill('E2E Waitlist Convert');
        // Username is capped at 30 characters — keep the prefix short so
        // the 13-digit timestamp still fits.
        await guest.getByLabel('Username').fill(`e2ewlconv${RUN}`);
        await guest.getByLabel('Email').fill(perEntryEmail);
        await guest.getByLabel('Password').fill('e2e_password_123');
        await guest.getByLabel('I agree to').check();
        await guest.getByRole('button', { name: 'Create account' }).click();
        await expect(guest).toHaveURL('/register/check-email');

        // Subject-matched: email is async, so the newest message for this
        // address can still be the invite email at the first poll.
        const verification = await getEmailWithSubject(
            request,
            perEntryEmail,
            'Confirm your account',
        );
        const verifyUrl = extractLink(
            verification.body,
            /https?:\/\/[^\s"<]+\/register\/verify[^\s"<]*/,
        );
        await guest.goto(verifyUrl);
        await expect(guest).toHaveURL('/welcome');
        await guest.getByRole('button', { name: 'Skip setup' }).click();
        await expect(guest).toHaveURL('/projects');

        // The redeemed entry now shows Converted in the admin list.
        await expectWaitlistStatus(admin, perEntryEmail, 'Converted');
    });
});
