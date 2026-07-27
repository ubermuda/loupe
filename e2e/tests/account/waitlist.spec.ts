/**
 * End-to-end coverage of the registration cap + waitlist loop: an admin
 * closes registration by setting the `registration.cap` feature flag, a
 * guest is diverted to /waitlist, an admin invites through all three admin
 * mechanisms (per-entry, bulk-selected, oldest-N), and the invited guest
 * registers through the invite link and ends up shown as Converted.
 *
 * Runs in its own Playwright project, serialized after the main suite
 * (`dependencies: ['chromium']` in playwright.config.ts) because it mutates
 * the global `registration.cap` flag that every other spec's registration
 * and OAuth-signup paths depend on being open. The cap is always restored
 * to open in a `finally` block, even on failure, so a crash here cannot
 * wedge every other run.
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

test.describe('registration cap and waitlist', () => {
    test('full loop: cap closes registration, waitlist joins, all three admin invite paths, invite redemption converts the entry', async ({
        page: admin,
        browser,
        request,
    }) => {
        // Exceeds Playwright's 30s default: this spec drives three separate
        // admin invite mechanisms plus a guest registration/invite-redemption
        // flow, each polling the admin list via expectWaitlistStatus().
        test.setTimeout(120_000);

        const perEntryEmail = `e2e-waitlist-perentry-${RUN}@example.com`;
        const selectedEmail = `e2e-waitlist-selected-${RUN}@example.com`;
        const oldestEmail = `e2e-waitlist-oldest-${RUN}@example.com`;

        // Explicit blank storageState: browser.newPage() otherwise inherits the
        // current test's storageState fixture — which createTest(ADMIN) above
        // overrides to the admin's authenticated session — so without this the
        // "guest" page silently starts out logged in as admin. See project-e2e's
        // "Guest test scoping" convention.
        const guest = await browser.newPage({
            storageState: { cookies: [], origins: [] },
        });

        // Only restore the cap when this test actually closed it: a failure
        // while closing it would otherwise throw AGAIN from the
        // finally-restore and mask the original error.
        let capClosed = false;

        try {
            await setRegistrationCap(admin, CLOSED_CAP);
            capClosed = true;

            // Guest hits /register while the gate is closed → diverted to the waitlist.
            await guest.goto('/register');
            await expect(guest).toHaveURL(/\/waitlist$/);
            await expect(guest.getByText('Registration is full')).toBeVisible();

            // Join with all three addresses — each mechanism below gets its own entry.
            for (const email of [perEntryEmail, selectedEmail, oldestEmail]) {
                await guest.goto('/waitlist');
                await guest.getByLabel('Email address').fill(email);
                await guest
                    .getByRole('button', { name: 'Join the waitlist' })
                    .click();
                await expect(
                    guest.getByText("You're on the list"),
                ).toBeVisible();
            }

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

            // Admin invites the first entry from its own row's "Invite" button.
            await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
            const perEntryRow = admin.locator('tr[data-waitlist-entry-id]', {
                hasText: perEntryEmail,
            });
            await perEntryRow
                .getByRole('button', { name: 'Invite', exact: true })
                .click();
            await expectWaitlistStatus(admin, perEntryEmail, 'Invited');

            // Admin invites the second entry via the checkbox + "Invite selected".
            await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
            const selectedRow = admin.locator('tr[data-waitlist-entry-id]', {
                hasText: selectedEmail,
            });
            await selectedRow.getByRole('checkbox').check();
            await admin
                .getByRole('button', { name: 'Invite selected', exact: true })
                .click();
            await expectWaitlistStatus(admin, selectedEmail, 'Invited');

            // Admin sweeps the rest (including the third entry) via "Invite
            // oldest", using the max allowed count so it isn't thrown off by any
            // older, still-uninvited debris left behind by a previous
            // interrupted run.
            await admin.goto('/admin/waitlist?sort=createdAt&dir=desc');
            await admin.getByLabel('Invite oldest').fill('100');
            await admin
                .getByRole('button', { name: 'Invite oldest', exact: true })
                .click();
            await expectWaitlistStatus(admin, oldestEmail, 'Invited');

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
        } finally {
            await guest.close();
            // Always reopen the gate, even on test failure — every other
            // spec's registration/OAuth path depends on it.
            // eslint-disable-next-line playwright/no-conditional-in-test -- deliberate restore guard, not test logic
            if (capClosed) {
                await setRegistrationCap(admin, OPEN_CAP);
            }
        }
    });
});
