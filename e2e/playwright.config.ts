import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    globalSetup: './global-setup.ts',
    testDir: './tests',
    fullyParallel: false,
    workers: 3,
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
            // The waitlist spec mutates the global registration.cap flag that every
            // other spec's registration/OAuth path depends on being open, and the
            // install spec wipes the database outright — both run in their own
            // projects below, serialized after this one finishes.
            testIgnore: [
                /account\/waitlist\.spec\.ts/,
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
            name: 'install-reset',
            testMatch: /install\/install\.spec\.ts/,
            // Depends on `waitlist` too, not just `chromium`: this project truncates
            // every table, so it must run strictly last — after the waitlist project's
            // registration.cap mutation has had its chance to run, not concurrently
            // with it.
            dependencies: ['chromium', 'waitlist'],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
