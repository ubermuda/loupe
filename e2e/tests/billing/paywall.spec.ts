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
    name: 'E2E Billing Paywall',
});

async function setBillingState(
    page: import('@playwright/test').Page,
    { enabled, expireTrial }: { enabled: boolean; expireTrial?: boolean },
): Promise<void> {
    const response = await page.request.get('/dev/billing-state', {
        params: {
            enabled: enabled ? '1' : '0',
            expireTrial: expireTrial ? '1' : '0',
        },
    });
    expect(response.status()).toBe(200);
}

test.afterEach(async ({ page }) => {
    await setBillingState(page, { enabled: false });
});

test('an expired trial paywalls the app but not the subscribe page', async ({
    page,
}) => {
    await setBillingState(page, { enabled: true, expireTrial: true });

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
