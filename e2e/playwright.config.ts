import { defineConfig, devices } from '@playwright/test';

// No default, deliberately. The `install-reset` project truncates every table,
// so the target is a destructive choice and guessing it wrongly costs a
// database. This used to fall back to the dev host, which meant running
// Playwright directly — a single spec, an IDE extension, `npx playwright test`
// — silently wiped the development data while `just e2e` looked fine, because
// only the recipe supplied the variable. Both recipes still do; anything else
// now has to say where it is aiming.
const baseURL = process.env.E2E_BASE_URL;

if (!baseURL) {
    throw new Error(
        'E2E_BASE_URL is not set, so there is no target to run against.\n' +
            'The suite truncates every table, so it will not pick one for you.\n' +
            'Use `just e2e` (the dedicated e2e target), or set E2E_BASE_URL explicitly.',
    );
}

// Per-request coverage collection takes one page render from about 0.5s to
// about 4.7s, measured with and without the X-Coverage header. Playwright's
// expect timeout is an absolute 5 seconds, so a coverage run sits on the bound
// and fails on timeouts rather than on anything a spec asserts. 20s is about
// four times the measured cost. The per-pull-request gate keeps 5s.
const collectingCoverage = !!process.env.COVERAGE;

export default defineConfig({
    globalSetup: './global-setup.ts',
    testDir: './tests',
    fullyParallel: false,
    timeout: collectingCoverage ? 120_000 : 30_000,
    expect: { timeout: collectingCoverage ? 20_000 : 5_000 },
    // Files run in parallel. A spec that flips a global flag or shares a fixed
    // account goes in a `workers: 1` project below, never in `chromium`.
    workers: 4,
    forbidOnly: !!process.env.CI,
    retries: 0,
    // Stop at the first failure. `waitlist`, `trial-end-lifecycle` and
    // `install-reset` depend on `chromium`, and Playwright skips a dependent
    // project when its dependency fails. Without this the run continues and
    // reports "N did not run" beside the failure, which reads as a deliberate
    // skip: one red test withheld all three suites for hours and nobody noticed.
    maxFailures: 1,
    reporter: [
        ['html', { open: 'never' }],
        // `just ci-report e2e-timing` reads this file from CI.
        ...(process.env.PLAYWRIGHT_JSON_OUTPUT_FILE ? [['json'] as const] : []),
    ],
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
        extraHTTPHeaders: {
            'X-Playwright': '1',
            // COVERAGE=1 makes the app collect per-request PHP coverage
            // (CoverageSubscriber from ubermuda/symfony-extra keys on this).
            ...(process.env.COVERAGE ? { 'X-Coverage': '1' } : {}),
        },
    },
    projects: [
        {
            name: 'chromium',
            // Specs that mutate state other files read run in the projects
            // below. Adding a spec here asserts that it is safe beside all of them.
            testIgnore: [
                /account\/waitlist\.spec\.ts/,
                /billing\/trial-end-lifecycle\.spec\.ts/,
                /install\/.*\.spec\.ts/,
                /board\/.*\.spec\.ts/,
                /admin\/.*\.spec\.ts/,
                /billing\/paywall\.spec\.ts/,
                /account\/social-login\.spec\.ts/,
            ],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'board',
            // Each board spec turns board.enabled off in its afterAll, which
            // 404s the board under any other board spec still running.
            testMatch: /board\/.*\.spec\.ts/,
            workers: 1,
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'admin',
            // Both specs register the one ADMIN_EMAIL account on first use.
            testMatch: /admin\/.*\.spec\.ts/,
            workers: 1,
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'global-flags',
            // billing.enabled and the OAuth provider flags change what every
            // signed-in page and the login form render, so nothing else runs
            // beside these.
            testMatch: [
                /billing\/paywall\.spec\.ts/,
                /account\/social-login\.spec\.ts/,
            ],
            workers: 1,
            use: {
                ...devices['Desktop Chrome'],
            },
            dependencies: ['chromium', 'board', 'admin'],
        },
        {
            name: 'waitlist',
            testMatch: /account\/waitlist\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
            dependencies: ['global-flags'],
        },
        {
            name: 'trial-end-lifecycle',
            testMatch: /billing\/trial-end-lifecycle\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
            // Serialized after waitlist: mutates registration.cap AND
            // billing.enabled, and its sweep trigger disables every
            // expired-trial account in the database — nothing else may be
            // registering users or relying on billing being off while it
            // runs. For a targeted run of this spec alone, pass --no-deps to
            // skip the dependency chain.
            dependencies: ['waitlist'],
        },
        {
            name: 'install-reset',
            testMatch: /install\/install\.spec\.ts/,
            // Strictly last in the chain: this project truncates every table,
            // so it must run after every other project — including
            // trial-end-lifecycle, whose fixture users and flag rows it would
            // otherwise destroy mid-run.
            dependencies: [
                'chromium',
                'board',
                'admin',
                'global-flags',
                'waitlist',
                'trial-end-lifecycle',
            ],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
