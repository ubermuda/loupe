import {
    test as base,
    expect,
    Browser,
    BrowserContext,
} from '@playwright/test';
import { request as playwrightRequest } from '@playwright/test';
import { Page } from '@playwright/test';
import { coverageScaled } from './timeouts';
import {
    Credentials,
    extractLink,
    getEmailWithSubject,
    latestEmailIdWithSubject,
    registerAndVerify,
    VERIFICATION_SUBJECT,
} from './helpers';

/**
 * Hides the Symfony Debug Toolbar by injecting a `display:none` style.
 */
export async function suppressToolbar(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const apply = () => {
            if (!document.getElementById('e2e-suppress-toolbar')) {
                const style = document.createElement('style');
                style.id = 'e2e-suppress-toolbar';
                style.textContent = '.sf-toolbar { display: none !important; }';
                (document.head ?? document.documentElement).appendChild(style);
            }
        };
        apply();
        setInterval(apply, 50);
    });
}

/**
 * Prevents the dogfooding site-review widget from mounting. The widget only
 * loads in envs where `SITE_REVIEW_WIDGET_PROJECT` is set (dev/e2e), and its
 * launcher is a `position:fixed` bottom-right shadow host that overlaps the
 * review console's bottom-pinned verdict bar — a dev-only overlay, like the
 * debug toolbar. Set the widget's own idempotency flag before its script runs
 * so it returns early and never appends the host. Use on review-console pages
 * whose controls sit under the launcher; never on `site-review/widget.spec.ts`,
 * which tests the widget itself.
 */
export async function suppressWidget(page: Page): Promise<void> {
    await page.addInitScript(() => {
        (
            window as unknown as { __loupeSiteReviewLoaded?: boolean }
        ).__loupeSiteReviewLoaded = true;
    });
}

/**
 * Signs a user in on a fresh browser context of its own, for a spec that
 * drives two browsers at once, such as a live-update spec with an editor and
 * a watcher. The project headers go to the app only: on the Mercure hub
 * request a custom header makes the EventSource preflight, which the hub
 * refuses.
 */
export async function signedInPage(
    browser: Browser,
    email: string,
    password: string,
): Promise<Page> {
    const page = await submitLogin(browser, email, password);
    // The first sign-in lands on /welcome, and a later one on the last project.
    // A cold worktree takes more than the default 5 seconds to answer the first one.
    await expect(page).not.toHaveURL(/\/login$/, {
        timeout: coverageScaled(15_000),
    });

    return page;
}

/**
 * A page of its own, with the login form filled in and submitted. The caller
 * says what proves the sign-in, because a page that asserts the landing page
 * also asserts whatever that page happens to render.
 */
async function submitLogin(
    browser: Browser,
    email: string,
    password: string,
): Promise<Page> {
    const { baseURL, extraHTTPHeaders, ignoreHTTPSErrors } =
        base.info().project.use;
    const context = await browser.newContext({
        baseURL,
        extraHTTPHeaders: {},
        ignoreHTTPSErrors,
        storageState: { cookies: [], origins: [] },
        viewport: { width: 1600, height: 900 },
    });
    const origin = new URL(baseURL ?? '').origin;
    await context.route(
        (url) => url.origin === origin,
        (route) =>
            route.continue({
                headers: { ...route.request().headers(), ...extraHTTPHeaders },
            }),
    );
    const page = await context.newPage();
    await suppressToolbar(page);
    await suppressWidget(page);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    // The submit rides Turbo, so nothing navigates. Wait for the answer, or a
    // caller that reads the session next reads it before the sign-in lands.
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().endsWith('/login') &&
                'POST' === response.request().method(),
            { timeout: coverageScaled(30_000) },
        ),
        page.getByRole('button', { name: 'Sign in' }).click(),
    ]);

    return page;
}

const appOrigin = (): string =>
    new URL(base.info().project.use.baseURL ?? '').origin;

/**
 * An access token for the user `page` is signed in as, from the dev-only mint
 * at `/dev/oauth/access-token`.
 *
 * The three flows that issue a token in production are covered by PHPUnit,
 * under `tests/Module/OAuth/`. Driving one of them here would cost a consent
 * page for every test that calls an API, and would test the flow again rather
 * than the endpoint the test is about.
 */
export async function accessToken(
    page: Page,
    scopes: string,
    projectId?: string,
): Promise<string> {
    const path = projectId
        ? `/dev/oauth/access-token/${projectId}`
        : '/dev/oauth/access-token';
    // Redirects off, so a page with no session fails here as a 302 rather than
    // following to the login page and handing back its HTML as JSON.
    const response = await page.request.post(path, {
        form: { scopes },
        maxRedirects: 0,
    });
    expect(
        response.status(),
        'the mint needs a signed-in page; 302 means this one carries no session',
    ).toBe(200);

    return (await response.json()).accessToken;
}

/** An `agent mcp` token, which is what the bridge and the MCP shim carry. */
export const agentAccessToken = (page: Page): Promise<string> =>
    accessToken(page, 'agent mcp');

export type SiteReviewGrant = {
    accessToken: string;
    refreshToken: string;
    expiresAt: number;
};

/** The session storage key the widget keeps its grant under. */
export const siteReviewGrantKey = (projectId: string): string =>
    `loupe-site-review:oauth:${appOrigin()}:${projectId}`;

/** Where the widget keeps the mode its notes go by, in local storage. */
export const siteReviewModeKey = (projectId: string): string =>
    `loupe-site-review:mode:${appOrigin()}:${projectId}`;

/**
 * Signs the site-review widget in on `page`, as the popup would. The grant
 * lands in session storage before any widget script runs, so the next
 * navigation boots signed in.
 *
 * The refresh token is a dead value. The mint issues none, and a widget that
 * refreshes therefore signs the reviewer out, which is what the tests about a
 * refused credential assert.
 */
export async function signWidgetIn(
    page: Page,
    projectId: string,
): Promise<SiteReviewGrant> {
    const grant: SiteReviewGrant = {
        accessToken: await accessToken(page, 'site-review', projectId),
        refreshToken: 'no-refresh-token-from-the-dev-mint',
        expiresAt: Date.now() + 3600 * 1000,
    };

    await page.addInitScript(
        ([key, value]) => {
            try {
                window.sessionStorage.setItem(key, value);
            } catch {
                /* the widget then asks the reviewer to sign in */
            }
        },
        [siteReviewGrantKey(projectId), JSON.stringify(grant)] as const,
    );

    return grant;
}

type StorageState = Awaited<ReturnType<BrowserContext['storageState']>>;

export const testWithVerifiedAccount = base.extend<{
    verifiedAccount: Credentials;
}>({
    verifiedAccount: [
        async ({ page, request }, use) => {
            const credentials = {
                email: `e2e-account-${crypto.randomUUID()}@example.com`,
                password: 'E2eAccountPassword1!',
            };
            await registerAndVerify(page, request, credentials);
            await use(credentials);
        },
        { timeout: coverageScaled(30_000) },
    ],
});

/**
 * Factory that creates a test object with a worker-scoped login for the given credentials.
 * Each spec file calls this with its own per-file user so tests in different files never share
 * a server-side PHP session. On first run the user doesn't exist yet — the fixture registers
 * and verifies it via Mailpit automatically.
 */
export function createTest(credentials: Credentials) {
    return base.extend<{}, { workerStorageState: StorageState }>({
        workerStorageState: [
            async ({ browser }, use, workerInfo) => {
                // Copied explicitly rather than relied upon: Playwright 1.60
                // does propagate `use` into a manual context, but without
                // X-Playwright this fixture's mail stays async and the suite
                // needs a worker again — too quiet a failure to leave implicit.
                const ctx = await browser.newContext({
                    baseURL: workerInfo.project.use.baseURL,
                    extraHTTPHeaders: workerInfo.project.use.extraHTTPHeaders,
                    ignoreHTTPSErrors: workerInfo.project.use.ignoreHTTPSErrors,
                });
                const page = await ctx.newPage();

                await page.goto('/login');
                await page.getByLabel('Email').fill(credentials.email);
                await page.getByLabel('Password').fill(credentials.password);
                await page.getByRole('button', { name: 'Sign in' }).click();

                // Three outcomes: logged in (logout form), unknown credentials
                // (auth error), or registered-but-unverified — redirected to
                // check-email, showing neither. The third belongs here or the
                // self-heal below is unreachable.
                await expect(
                    page
                        .locator('form[action="/logout"]')
                        .or(page.locator('.auth-error'))
                        .or(
                            page.getByRole('button', {
                                name: 'Resend verification email',
                            }),
                        ),
                ).toBeVisible({ timeout: coverageScaled(15000) });

                if (await page.locator('.auth-error').isVisible()) {
                    const requestContext = await playwrightRequest.newContext();
                    await registerAndVerify(page, requestContext, credentials);
                    await requestContext.dispose();
                } else if (page.url().includes('/register/check-email')) {
                    // The account exists but was never verified — an earlier run
                    // crashed between registering and following the link.
                    // Self-heal: resend, follow the FRESH link (the inbox may
                    // hold stale ones), and finish the wizard.
                    const requestContext = await playwrightRequest.newContext();
                    const previous = await latestEmailIdWithSubject(
                        requestContext,
                        credentials.email,
                        VERIFICATION_SUBJECT,
                    );
                    await page
                        .getByRole('button', {
                            name: 'Resend verification email',
                        })
                        .click();
                    const received = await getEmailWithSubject(
                        requestContext,
                        credentials.email,
                        VERIFICATION_SUBJECT,
                        30000,
                        previous,
                    );
                    await requestContext.dispose();
                    const link = extractLink(
                        received.body,
                        /https?:\/\/[^\s"<]+\/register\/verify[^\s"<]*/,
                    );
                    await page.goto(link);
                    if (page.url().includes('/welcome')) {
                        await page
                            .getByRole('button', { name: 'Skip setup' })
                            .click();
                    }
                }

                // Wait for the session to be established before snapshotting
                // cookies, or the storage state races the login POST.
                await expect(
                    page.locator('form[action="/logout"]'),
                ).toBeVisible();

                const storageState = await ctx.storageState();
                await ctx.close();
                await use(storageState);
            },
            { scope: 'worker' },
        ],

        storageState: ({ workerStorageState }, use) => use(workerStorageState),
    });
}
