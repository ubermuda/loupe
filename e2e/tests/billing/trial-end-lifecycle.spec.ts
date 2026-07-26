/**
 * End-to-end proof of the trial-end lifecycle: the sweep disables an expired
 * trial (and a canceled-past-period subscription), the paywall bounces the
 * disabled account to the subscribe page showing the trial-expired state, and
 * — when the registration cap is full — the subscribe page offers the waitlist
 * CTA instead of checkout, whose submission must succeed in a real browser
 * (the stateless-CSRF double-submit that WebTestCase can only pin via markup).
 *
 * Billing state is driven through the dev-only seam `/dev/billing-state`
 * (`#[When('dev')]`): it seeds the signed-in user's profile into a named
 * lifecycle state and runs TrialEndSweeper synchronously, so no messenger
 * worker is required here (survey emails are skipped while no
 * `billing.survey_url.*` flag is set, and nothing here asserts mail).
 *
 * Runs in its own Playwright project, serialized after the waitlist project
 * (`dependencies: ['waitlist']` in playwright.config.ts): it mutates the
 * global `billing.enabled` and `registration.cap` flags, and the sweep
 * disables EVERY expired-trial account in the database — nothing else may be
 * registering users or relying on billing being off while it runs. Both flags
 * are always restored — `billing.enabled` (plus the user's profile) in
 * afterEach, `registration.cap` in a `finally` mirroring waitlist.spec.ts —
 * so a crash here cannot wedge every other run.
 */

import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { createTest } from '../fixtures';
import { type Credentials, registerAndVerify } from '../helpers';

const test = createTest({
    email: 'e2e-billing-lifecycle@example.com',
    password: 'e2e_password_123',
    name: 'E2E Billing Lifecycle',
});

// Matches ADMIN_EMAIL in compose.yaml (see admin-smoke.spec.ts) — logging in
// as this address is what PromoteAdminUserListener keys on to grant
// ROLE_ADMIN, needed to edit the registration.cap flag through the admin UI.
const ADMIN: Credentials = {
    email: 'e2e-admin-smoke@example.com',
    password: 'e2e_password_123',
    name: 'E2E Admin Smoke',
};

// Any value >= 1 closes the gate: RegistrationGate.isOpen() compares the
// active-user count against the cap, and the admin account alone already
// meets a cap of 1. See waitlist.spec.ts, which uses the same bounds.
const CLOSED_CAP = 1;
const OPEN_CAP = 0;

const TRIAL_EXPIRED_TEXT =
    'Your trial has ended. Subscribe to keep using the app.';

interface SweepCounts {
    disabled: number;
    churnedSurveys: number;
    subscriberSurveys: number;
    cancelSurveys: number;
    failed: number;
}

/**
 * Drives the dev seam. Flag flips and the sweep happen in separate calls in
 * the tests below because the flag reader is request-cached: a sweep in the
 * same request as the `enabled` flip may not see the new value.
 */
async function setBillingState(
    page: Page,
    {
        enabled,
        state,
        sweep,
    }: { enabled: boolean; state?: string; sweep?: boolean },
): Promise<{ sweep?: SweepCounts }> {
    const response = await page.request.get('/dev/billing-state', {
        params: {
            enabled: enabled ? '1' : '0',
            ...(state ? { state } : {}),
            ...(sweep ? { sweep: '1' } : {}),
        },
    });
    expect(response.status()).toBe(200);
    return (await response.json()) as { sweep?: SweepCounts };
}

/** Runs the sweep through the seam and asserts no row failed. */
async function runSweep(page: Page): Promise<SweepCounts> {
    const result = await setBillingState(page, { enabled: true, sweep: true });
    expect(result.sweep).toBeDefined();
    expect(result.sweep!.failed).toBe(0);
    return result.sweep!;
}

/**
 * Logs the admin in on its own page, registering the account first if this
 * database has never seen it — the same login-or-register flow as the
 * createTest worker fixture.
 */
async function adminLogin(
    page: Page,
    request: APIRequestContext,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(
        page.locator('form[action="/logout"]').or(page.locator('.auth-error')),
    ).toBeVisible();

    if (await page.locator('.auth-error').isVisible()) {
        await registerAndVerify(page, request, ADMIN);
    }

    await expect(page).toHaveURL('/projects');
}

/**
 * Creates or edits the singleton `registration.cap` int flag via the real
 * admin UI. Copied from waitlist.spec.ts — including the re-navigate-and-poll
 * verification, because the flag edit form redirects back to its own URL
 * (the "form redirects to the same URL" trap).
 */
async function setRegistrationCap(admin: Page, value: number): Promise<void> {
    await admin.goto('/admin/feature-flags?q=registration.cap');
    const existingRow = admin.locator('tr', { hasText: 'registration.cap' });

    if (0 === (await existingRow.count())) {
        await admin
            .getByRole('link', { name: 'New flag', exact: true })
            .click();
        await expect(
            admin.getByRole('heading', {
                name: 'New Feature Flag',
                exact: true,
            }),
        ).toBeVisible();
        await admin
            .getByLabel('Name', { exact: true })
            .fill('registration.cap');
    } else {
        await existingRow
            .getByRole('link', { name: 'Edit', exact: true })
            .click();
        await expect(
            admin.getByRole('heading', {
                name: 'Edit Feature Flag',
                exact: true,
            }),
        ).toBeVisible();
    }

    await admin.getByLabel('Type', { exact: true }).selectOption('int');
    await admin
        .locator('[data-feature-flag-form-target="intField"]')
        .getByLabel('Value', { exact: true })
        .fill(String(value));
    await admin.getByRole('button', { name: 'Save' }).click();

    await expect(async () => {
        await admin.goto('/admin/feature-flags?q=registration.cap');
        await expect(
            admin
                .locator('tr', { hasText: 'registration.cap' })
                .getByText(String(value), { exact: true }),
        ).toBeVisible();
    }).toPass({ timeout: 15000 });
}

test.afterEach(async ({ page }) => {
    // Restore EVERYTHING this spec's seam calls mutate: billing.enabled back
    // off, and the worker user back to an enabled account on a fresh trial —
    // leaving it disabled would poison the next run's fixture login.
    await setBillingState(page, { enabled: false, state: 'fresh-trial' });
});

test('the sweep disables an expired trial and the paywall lands on the trial-expired subscribe page', async ({
    page,
}) => {
    await setBillingState(page, { enabled: true, state: 'expired-trial' });

    const counts = await runSweep(page);
    // At least this user: the sweep is global, so parallel debris (e.g. the
    // paywall spec's expired user) may legitimately add to the count.
    expect(counts.disabled).toBeGreaterThanOrEqual(1);
    expect(counts.churnedSurveys).toBeGreaterThanOrEqual(1);

    // The account is now disabled — the observable is the paywall bounce.
    await page.goto('/projects');
    await expect(page).toHaveURL(/\/billing\/subscribe$/);
    await expect(page.getByText(TRIAL_EXPIRED_TEXT)).toBeVisible();
    // With the cap open the page still offers checkout, not the waitlist.
    await expect(
        page.getByRole('button', { name: 'Subscribe', exact: true }),
    ).toBeVisible();

    // Marker idempotency, browser-real: a second sweep re-selects nothing.
    const second = await runSweep(page);
    expect(second.disabled).toBe(0);
    expect(second.churnedSurveys).toBe(0);
});

test('the sweep disables a canceled subscription past its period', async ({
    page,
}) => {
    await setBillingState(page, {
        enabled: true,
        state: 'canceled-past-period',
    });

    const counts = await runSweep(page);
    expect(counts.disabled).toBeGreaterThanOrEqual(1);
    expect(counts.cancelSurveys).toBeGreaterThanOrEqual(1);

    await page.goto('/projects');
    await expect(page).toHaveURL(/\/billing\/subscribe$/);
    await expect(page.getByText(TRIAL_EXPIRED_TEXT)).toBeVisible();
});

test('with the cap full a disabled account is offered the waitlist and joining succeeds', async ({
    page,
    browser,
    request,
}) => {
    await setBillingState(page, { enabled: true, state: 'disabled' });

    const adminCtx = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const admin = await adminCtx.newPage();

    try {
        await adminLogin(admin, request);
        await setRegistrationCap(admin, CLOSED_CAP);

        await page.goto('/billing/subscribe');
        await expect(page.getByText('We are at capacity')).toBeVisible();
        const joinButton = page.getByRole('button', {
            name: 'Join the waitlist',
        });
        await expect(joinButton).toBeVisible();
        // The checkout button must NOT be offered — a POST would be rejected.
        await expect(
            page.getByRole('button', { name: 'Subscribe', exact: true }),
        ).toHaveCount(0);

        // The CTA is a hand-rolled form using the stateless double-submit
        // CSRF token: landing on the confirmation (not a 403/validation
        // error) is the browser-real proof that the token round-trips.
        await joinButton.click();
        await expect(page.getByText("You're on the list")).toBeVisible();
    } finally {
        // Always reopen the gate, even on failure — every other spec's
        // registration/OAuth path depends on it.
        await setRegistrationCap(admin, OPEN_CAP);
        await adminCtx.close();
    }
});
