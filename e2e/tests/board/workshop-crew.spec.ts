import { expect } from '@playwright/test';
import { agentAccessToken, createTest } from '../fixtures';

const test = createTest({
    email: `e2e-workshop-crew-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

test('Workshop shows reported connections and opens the matching details', async ({
    page,
}, testInfo) => {
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Crew project', markdown: '# Crew' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    const workshopUrl = `/projects/${projectId}`;
    await page.goto(workshopUrl);
    const empty = page.locator('[data-workshop-crew-empty]');
    await expect(empty).toBeVisible();
    await expect(empty).toHaveAttribute('href', `${workshopUrl}/connect`);
    await empty.click();
    await expect(page).toHaveURL(`${workshopUrl}/connect`);

    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const version = 'v'.repeat(100);
    const heartbeat = await page.request.put(
        `/api/bridges/${bridgeId}/heartbeat`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: { projects: [projectId], cliVersion: version },
        },
    );
    expect(heartbeat.status()).toBe(200);
    await page.goto(workshopUrl);
    const connection = page.locator(`[data-workshop-connection="${bridgeId}"]`);
    await expect(connection).toBeVisible();
    await expect(empty).toHaveCount(0);
    await expect(connection).toContainText(version);
    await expect(connection).toContainText('Healthy');
    for (const fontSize of ['100%', '200%']) {
        await page.evaluate((size) => {
            document.documentElement.style.fontSize = size;
        }, fontSize);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await expect
                .poll(() =>
                    connection.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            await expect
                .poll(() =>
                    page.evaluate(
                        () => document.documentElement.scrollWidth - innerWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            await connection.scrollIntoViewIfNeeded();
            const bounds = await connection.boundingBox();
            expect(bounds).not.toBeNull();
            expect(bounds!.x).toBeGreaterThanOrEqual(0);
            expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
            await page.screenshot({
                path: testInfo.outputPath(
                    `workshop-crew-${width}-${fontSize}.png`,
                ),
                animations: 'disabled',
            });
        }
    }
    await connection.focus();
    await page.keyboard.press('Enter');
    const details = page.locator(`#agent-connection-${bridgeId}`);
    await expect(details).toBeVisible();
    await expect(details).toContainText(version);
    await expect(details).toBeFocused();
    await expect(page).toHaveURL(
        `${workshopUrl}/agents#agent-connection-${bridgeId}`,
    );
});
