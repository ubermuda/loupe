import { defineConfig, devices } from '@playwright/test';

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
    reporter: [['html', { open: 'never' }]],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'https://loupe.dev.localhost',
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
