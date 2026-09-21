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

const CLI_CLIENT_ID = 'loupe-cli';
const DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';
const WIDGET_CLIENT_ID = 'loupe-site-review-widget';

const appOrigin = (): string =>
    new URL(base.info().project.use.baseURL ?? '').origin;

const base64Url = (bytes: Uint8Array): string =>
    Buffer.from(bytes).toString('base64url');

/**
 * An `agent mcp projects` access token for the user `page` is signed in as.
 * It comes from Loupe's device flow, the path the CLI takes, so the page
 * approves the consent itself. It leaves the page on the consent form,
 * because Turbo renders no 200 answer to a form submission.
 */
export async function agentAccessToken(page: Page): Promise<string> {
    const start = await page.request.post('/oauth/device-authorization', {
        form: { client_id: CLI_CLIENT_ID, scope: 'agent mcp projects' },
    });
    expect(start.status()).toBe(200);
    const started = await start.json();

    // The absolute URI names the instance's own host, which is not the host
    // the suite runs against. Keep the path, so the approval stays on target.
    const verify = new URL(started.verification_uri_complete);
    await page.goto(`${verify.pathname}${verify.search}`);
    // The consent rides Turbo, so the answer is a fetch rather than a
    // navigation. Read the answer itself: a refused form comes back 422, and a
    // 200 says the page accepted the approval. The poll below is what proves
    // the grant, because Turbo renders no 200 answer to a form.
    const [consent] = await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().includes(verify.pathname) &&
                'POST' === response.request().method(),
            { timeout: coverageScaled(30_000) },
        ),
        page.getByRole('button', { name: 'Allow' }).click(),
    ]);
    expect(consent.status()).toBe(200);

    // Approval comes first, so the first poll answers with the tokens. The
    // retry covers a slow write, and it waits out the flow's own interval.
    for (let attempt = 0; attempt < 3; attempt++) {
        const response = await page.request.post('/oauth/token', {
            form: {
                grant_type: DEVICE_GRANT,
                device_code: started.device_code,
                client_id: CLI_CLIENT_ID,
            },
        });
        const payload = await response.json();
        if (typeof payload.access_token === 'string') {
            return payload.access_token;
        }
        expect(['authorization_pending', 'slow_down']).toContain(payload.error);
        await page.waitForTimeout((started.interval + 1) * 1000);
    }

    throw new Error('The device flow gave no access token.');
}

export type SiteReviewGrant = {
    accessToken: string;
    refreshToken: string;
    expiresAt: number;
};

/** The session storage key the widget keeps its grant under. */
export const siteReviewGrantKey = (projectId: string): string =>
    `loupe-site-review:oauth:${appOrigin()}:${projectId}`;

/**
 * A `site-review` grant for `projectId`, from the widget's own OAuth client
 * and the real authorize endpoint. The sign-in runs in a context of its own,
 * so the caller's page keeps the session it had.
 */
async function siteReviewGrant(
    page: Page,
    credentials: Credentials,
    projectId: string,
): Promise<SiteReviewGrant> {
    // A second context, a sign-in and two consent pages. Only the test that
    // mints pays it, so the budget grows here rather than for the whole file.
    base.info().setTimeout(base.info().timeout + coverageScaled(30_000));

    const origin = appOrigin();
    const redirectUri = `${origin}/oauth/widget/callback`;
    const verifier = base64Url(crypto.getRandomValues(new Uint8Array(32)));
    const challenge = base64Url(
        new Uint8Array(
            await crypto.subtle.digest(
                'SHA-256',
                new TextEncoder().encode(verifier),
            ),
        ),
    );

    const browser = page.context().browser();
    if (null === browser) {
        throw new Error('A widget sign-in needs a browser-backed page.');
    }
    const signIn = await submitLogin(
        browser,
        credentials.email,
        credentials.password,
    );
    try {
        // The form submits over Turbo, so nothing navigates and the session
        // arrives on its own schedule. The authorize request below redirects
        // to /login until it is there, so wait for a page that needs one.
        await expect
            .poll(
                () =>
                    signIn.request
                        // Redirects off: a signed-out request answers 302 to
                        // the login page, which follows to a 200 of its own.
                        .get('/account/profile', { maxRedirects: 0 })
                        .then((response) => response.status()),
                { timeout: coverageScaled(30_000) },
            )
            .toBe(200);

        await signIn.goto(
            `/oauth/authorize?${new URLSearchParams({
                response_type: 'code',
                client_id: WIDGET_CLIENT_ID,
                redirect_uri: redirectUri,
                scope: 'site-review',
                state: base64Url(crypto.getRandomValues(new Uint8Array(16))),
                code_challenge: challenge,
                code_challenge_method: 'S256',
                project: projectId,
                origin,
            })}`,
        );
        // The consent page renders for a signed-in user alone, so it is what
        // proves the sign-in above.
        await expect(signIn.getByTestId('oauth-consent')).toBeVisible({
            timeout: coverageScaled(15_000),
        });
        await signIn.getByRole('button', { name: 'Allow' }).click();

        // The callback page drops its own query string, so read the code from
        // the message it would post to the widget.
        const callback = signIn.getByTestId('oauth-widget-callback');
        await expect(callback).toBeAttached({
            timeout: coverageScaled(15_000),
        });
        const message = JSON.parse(
            (await callback.getAttribute('data-message')) ?? '{}',
        );
        expect(typeof message.code).toBe('string');

        const response = await signIn.request.post('/oauth/token', {
            form: {
                grant_type: 'authorization_code',
                client_id: WIDGET_CLIENT_ID,
                code: message.code,
                redirect_uri: redirectUri,
                code_verifier: verifier,
            },
        });
        expect(response.status()).toBe(200);
        const payload = await response.json();

        return {
            accessToken: payload.access_token,
            refreshToken: payload.refresh_token,
            expiresAt: Date.now() + payload.expires_in * 1000,
        };
    } finally {
        await signIn.context().close();
    }
}

/**
 * One grant per user and project, for the life of the worker process. A fresh
 * sign-in costs a second browser context and four round trips, which is most
 * of a spec's budget when every test pays it.
 */
const grants = new Map<string, SiteReviewGrant>();

/**
 * Signs the site-review widget in on `page`, the way the OAuth popup does.
 * The grant lands in session storage before any widget script runs, so the
 * next navigation boots signed in.
 */
export async function signWidgetIn(
    page: Page,
    credentials: Credentials,
    projectId: string,
): Promise<SiteReviewGrant> {
    const cacheKey = `${credentials.email}\u0000${projectId}`;
    let grant = grants.get(cacheKey);
    // A widget that refreshed rotated the access token away from the cached
    // one, so ask the API rather than trust the copy.
    if (grant && !(await stillGranted(page, grant))) {
        grant = undefined;
    }
    if (!grant) {
        grant = await siteReviewGrant(page, credentials, projectId);
        grants.set(cacheKey, grant);
    }
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

const stillGranted = async (
    page: Page,
    grant: SiteReviewGrant,
): Promise<boolean> => {
    const response = await page.request.get('/api/site-review/review', {
        headers: { Authorization: `Bearer ${grant.accessToken}` },
    });

    return response.ok();
};

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
