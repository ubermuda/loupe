/**
 * End-to-end coverage of social login with the OAuth provider boundary stubbed.
 *
 * The real `/oauth/{provider}/check` callback needs a live provider round-trip
 * (authorization redirect, token exchange, userinfo), which cannot run in e2e.
 * The spec instead drives the dev-only seam `GET /dev/e2e/social-login`, which
 * builds a SocialProfile from query parameters and runs it through the real
 * resolver and a real programmatic login — so branch order, the password-link
 * hand-off and the session are exercised for real; only the provider HTTP call
 * is faked.
 *
 * The seam is a GET so `page.goto(...)` drives it inside the browser context:
 * the session cookie it sets then belongs to the page. A request-fixture POST
 * would deposit that cookie in a separate jar and leave the page signed out.
 *
 * No database reset: like the other seed-based specs, this file coexists with
 * parallel ones by using run-unique provider identities and email addresses.
 */

import {
    test,
    expect,
    type Page,
    type APIRequestContext,
} from '@playwright/test';
import { registerAndVerify, logout } from '../helpers';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

async function setProviderFlag(
    request: APIRequestContext,
    provider: 'google' | 'github',
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: `auth.${provider}.enabled`, enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

/** Stand in for a completed OAuth callback and wait for the seam to redirect. */
async function socialLogin(
    page: Page,
    params: {
        provider: 'google' | 'github';
        providerUserId: string;
        email?: string;
        fullName?: string;
        emailVerified?: '0' | '1';
    },
): Promise<void> {
    const query = new URLSearchParams(
        Object.entries(params).filter(([, value]) => value !== undefined) as [
            string,
            string,
        ][],
    ).toString();
    await page.goto(`/dev/e2e/social-login?${query}`);
    await expect(page).not.toHaveURL(/\/dev\/e2e\/social-login/);
}

// beforeEach rather than beforeAll: `request` is a test-scoped fixture, and the
// upsert is cheap and idempotent, so every test starts from both providers on.
test.beforeEach(async ({ request }) => {
    await setProviderFlag(request, 'google', true);
    await setProviderFlag(request, 'github', true);
});

test('a first social sign-in creates a verified account, and a repeat sign-in reuses it', async ({
    page,
}) => {
    const providerUserId = `google-uid-${RUN}`;
    const email = `social+${RUN}@example.com`;

    await socialLogin(page, {
        provider: 'google',
        providerUserId,
        email,
        fullName: 'Social One',
    });
    // A brand-new account owns no project and hasn't completed the first-run
    // wizard yet, so HomeController lands it on /welcome rather than /projects.
    await expect(page).toHaveURL('/welcome');
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();

    // Skip the wizard so the account reaches the same steady state as any
    // other verified user — and so the repeat sign-in below has a stable,
    // wizard-independent landing page to assert account reuse against.
    await page.getByRole('button', { name: 'Skip setup' }).click();
    await expect(page).toHaveURL('/projects');

    await logout(page);

    // The same provider identity must land back in the same account rather than
    // creating a second one.
    await socialLogin(page, {
        provider: 'google',
        providerUserId,
        email,
        fullName: 'Social One',
    });
    await expect(page).toHaveURL('/projects');
});

test('an unverified provider email is refused with a visible error', async ({
    page,
}) => {
    await socialLogin(page, {
        provider: 'github',
        providerUserId: `github-uid-unverified-${RUN}`,
        email: `unverified+${RUN}@example.com`,
        fullName: 'Unverified One',
        emailVerified: '0',
    });

    await expect(page).toHaveURL('/login?social_error=unverified');
    await expect(page.locator('.auth-error')).toContainText(
        'no verified email address',
    );
});

test('a collision with a password account requires the password before linking', async ({
    page,
    request,
}) => {
    const email = `collide+${RUN}@example.com`;
    const password = 'SecurePassword1!';

    await registerAndVerify(page, request, {
        email,
        password,
    });
    await logout(page);

    await socialLogin(page, {
        provider: 'google',
        providerUserId: `google-uid-collide-${RUN}`,
        email,
        fullName: 'Collider',
    });

    await expect(page).toHaveURL('/oauth/link');
    await expect(
        page.getByRole('heading', { name: 'Confirm your password' }),
    ).toBeVisible();

    // A wrong password must not link anything.
    await page.getByLabel('Password', { exact: true }).fill('WrongPassword!');
    await page
        .getByRole('button', { name: 'Link account and sign in' })
        .click();
    await expect(page.locator('.field-errors')).toContainText('incorrect');

    await page.getByLabel('Password', { exact: true }).fill(password);
    await page
        .getByRole('button', { name: 'Link account and sign in' })
        .click();

    await expect(page).toHaveURL('/projects');
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
});

test('provider buttons follow their feature flag on the login and register pages', async ({
    page,
    request,
}) => {
    await setProviderFlag(request, 'github', false);

    for (const path of ['/login', '/register']) {
        await page.goto(path);
        await expect(page.locator('a[href="/oauth/google"]')).toBeVisible();
        await expect(page.locator('a[href="/oauth/github"]')).toHaveCount(0);
    }

    await setProviderFlag(request, 'github', true);

    for (const path of ['/login', '/register']) {
        await page.goto(path);
        await expect(page.locator('a[href="/oauth/github"]')).toBeVisible();
    }
});
