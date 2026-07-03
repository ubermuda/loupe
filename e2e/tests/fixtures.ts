import { test as base, BrowserContext } from '@playwright/test';
import { Page } from '@playwright/test';

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
            window as unknown as { __betterplansSiteReviewLoaded?: boolean }
        ).__betterplansSiteReviewLoaded = true;
    });
}

type StorageState = Awaited<ReturnType<BrowserContext['storageState']>>;

interface Credentials {
    email: string;
    password: string;
}

/**
 * Factory that creates a test object with a worker-scoped login for the given credentials.
 * Each spec file calls this with its own per-file user so tests in different files never share
 * a server-side PHP session.
 */
export function createTest(credentials: Credentials) {
    return base.extend<{}, { workerStorageState: StorageState }>({
        workerStorageState: [
            async ({ browser }, use) => {
                const ctx = await browser.newContext({
                    baseURL: 'http://localhost',
                });
                const page = await ctx.newPage();

                await page.goto('/login');
                await page.getByLabel('Email').fill(credentials.email);
                await page.getByLabel('Password').fill(credentials.password);
                await page.getByRole('button', { name: 'Sign in' }).click();

                const storageState = await ctx.storageState();
                await ctx.close();
                await use(storageState);
            },
            { scope: 'worker' },
        ],

        storageState: ({ workerStorageState }, use) => use(workerStorageState),
    });
}
