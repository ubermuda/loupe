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
 * lifecycle state and runs the trial-end sweep synchronously, so no messenger
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
import { adminLogin, setRegistrationCap } from '../admin-helpers';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-billing-lifecycle@example.com',
    password: 'e2e_password_123',
    name: 'E2E Billing Lifecycle',
});

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

/**
 * Runs the sweep through the seam and asserts no row failed. Also (re)asserts
 * billing.enabled — the sweep no-ops when the flag is off.
 */
async function runSweep(page: Page): Promise<SweepCounts> {
    const result = await setBillingState(page, { enabled: true, sweep: true });
    expect(result.sweep).toBeDefined();
    expect(result.sweep!.failed).toBe(0);
    return result.sweep!;
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

    // Only restore the cap when this test actually closed it: a failure in
    // adminLogin (or in closing the cap) would otherwise throw AGAIN from the
    // finally-restore and mask the original error.
    let capClosed = false;

    try {
        await adminLogin(admin, request);
        await setRegistrationCap(admin, CLOSED_CAP);
        capClosed = true;

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
        // Always reopen the gate, even on test failure — every other spec's
        // registration/OAuth path depends on it.
        // eslint-disable-next-line playwright/no-conditional-in-test -- deliberate restore guard, not test logic
        if (capClosed) {
            await setRegistrationCap(admin, OPEN_CAP);
        }
        await adminCtx.close();
    }
});
