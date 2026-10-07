import { request } from '@playwright/test';

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
}

export default globalSetup;
