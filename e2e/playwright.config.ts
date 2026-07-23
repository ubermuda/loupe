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
        },
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
});
