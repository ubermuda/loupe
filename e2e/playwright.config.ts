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
            testIgnore: /install\/.*\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'install-reset',
            testMatch: /install\/install\.spec\.ts/,
            dependencies: ['chromium'],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
