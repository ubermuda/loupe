/**
 * End-to-end proof of the paywall: with `billing.enabled` on and the trial
 * expired, any app page redirects to the subscribe page, and the subscribe page
 * itself still loads (no redirect loop).
 *
 * Billing state is driven through the dev-only seam `/dev/billing-state`
 * (`#[When('dev')]`), which flips the flag and expires the signed-in user's
 * trial. The flag is global, so the spec always switches it back off — the seam
 * route is allowlisted in the paywall listener precisely so this reset works
 * from a paywalled session.
 */

import { expect } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-billing-paywall@example.com',
    password: 'e2e_password_123',
});

async function setBillingState(
    page: import('@playwright/test').Page,
    { enabled, state }: { enabled: boolean; state?: string },
): Promise<void> {
    const response = await page.request.get('/dev/billing-state', {
        params: {
            enabled: enabled ? '1' : '0',
            ...(state ? { state } : {}),
        },
    });
    expect(response.status()).toBe(200);
}

test.afterEach(async ({ page }) => {
    // Billing off AND the user back on a fresh trial: leaving the trial
    // expired would make this user permanent debris for the trial-end
    // lifecycle spec's global sweep.
    await setBillingState(page, { enabled: false, state: 'fresh-trial' });
});

test('an expired trial paywalls the app but not the subscribe page', async ({
    page,
}) => {
    await setBillingState(page, { enabled: true, state: 'expired-trial' });

    await page.goto('/projects');

    await expect(page).toHaveURL(/\/billing\/subscribe$/);
    await expect(
        page.getByRole('heading', { name: 'Subscribe', exact: true }),
    ).toBeVisible();

    // The subscribe page is reachable directly — the listener allowlists it, so
    // there is no redirect loop.
    await page.goto('/billing/subscribe');
    await expect(page).toHaveURL(/\/billing\/subscribe$/);
    await expect(
        page.getByRole('heading', { name: 'Subscribe', exact: true }),
    ).toBeVisible();
});

test('with billing disabled the app is reachable again', async ({ page }) => {
    await setBillingState(page, { enabled: false });

    await page.goto('/projects');

    await expect(page).toHaveURL(/\/projects$/);
});
