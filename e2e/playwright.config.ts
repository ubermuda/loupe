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

export default defineConfig({
    globalSetup: './global-setup.ts',
    testDir: './tests',
    fullyParallel: false,
    // Mailpit is shared by every spec and is never cleared, so concurrent
    // mail-asserting specs read each other's messages. The suite is serial by
    // nature; saying so here means the plain `just e2e` is correct rather than
    // correct-only-if-you-remember-the-flag. Override on the command line when
    // running a subset that touches no mail.
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    // Stop at the first failure. `waitlist`, `trial-end-lifecycle` and
    // `install-reset` depend on `chromium`, and Playwright skips a dependent
    // project when its dependency fails. Without this the run continues and
    // reports "N did not run" beside the failure, which reads as a deliberate
    // skip: one red test withheld all three suites for hours and nobody noticed.
    maxFailures: 1,
    reporter: [['html', { open: 'never' }]],
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
            // The waitlist and trial-end-lifecycle specs mutate global feature
            // flags (registration.cap, billing.enabled) that every other spec
            // depends on, and the install spec wipes the database outright —
            // all three run in their own projects below, serialized after
            // this one finishes.
            testIgnore: [
                /account\/waitlist\.spec\.ts/,
                /billing\/trial-end-lifecycle\.spec\.ts/,
                /install\/.*\.spec\.ts/,
            ],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'waitlist',
            testMatch: /account\/waitlist\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
            dependencies: ['chromium'],
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
            dependencies: ['chromium', 'waitlist', 'trial-end-lifecycle'],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
