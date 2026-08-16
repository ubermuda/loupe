import { test as base, expect, BrowserContext } from '@playwright/test';
import { request as playwrightRequest } from '@playwright/test';
import { Page } from '@playwright/test';
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
 * loads in envs where `SITE_REVIEW_WIDGET_TOKEN` is set (dev/e2e), and its
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

type StorageState = Awaited<ReturnType<BrowserContext['storageState']>>;

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
                // A manual context inherits nothing from `use`, so each option
                // is copied. Without X-Playwright the fixture's own mail stays
                // async and the suite needs a worker again.
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
                ).toBeVisible();

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
                await expect(page).toHaveURL('/projects');

                const storageState = await ctx.storageState();
                await ctx.close();
                await use(storageState);
            },
            { scope: 'worker' },
        ],

        storageState: ({ workerStorageState }, use) => use(workerStorageState),
    });
}
