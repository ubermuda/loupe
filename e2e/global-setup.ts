import { chromium, request } from '@playwright/test';

// Live updates hold one value for the whole run, so no spec turns them off
// under another spec that waits on the hub.
async function globalSetup(): Promise<void> {
    const context = await request.newContext({
        baseURL: process.env.E2E_BASE_URL,
        ignoreHTTPSErrors: true,
        extraHTTPHeaders: { 'X-Playwright': '1' },
    });

    try {
        const response = await context.post('/dev/e2e/feature-flag', {
            form: { name: 'live_updates.enabled', enabled: 1 },
        });
        if (!response.ok()) {
            throw new Error(
                `Turning on live_updates.enabled failed: ${response.status()} ${await response.text()}`,
            );
        }
    } finally {
        await context.dispose();
    }

    await warmUp();
}

// The first page load on a fresh container compiles every asset on demand. Four
// workers that each pay that at once can pass the 30s fixture timeout on CI.
async function warmUp(): Promise<void> {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({
            baseURL: process.env.E2E_BASE_URL,
            ignoreHTTPSErrors: true,
            extraHTTPHeaders: { 'X-Playwright': '1' },
        });
        await page.goto('/login', { waitUntil: 'load', timeout: 120_000 });
    } finally {
        await browser.close();
    }
}

export default globalSetup;
