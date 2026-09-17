import { expect } from '@playwright/test';
import { createTest, suppressWidget } from '../fixtures';
import { submitRedirectingForm } from '../helpers';

const test = createTest({
    email: `e2e-run-history-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

test('completed reports retain outcomes and escaped output at enlarged text sizes', async ({
    page,
}, testInfo) => {
    await suppressWidget(page);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Run history', markdown: '# Runs' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    await page.goto('/account?tab=api-tokens');
    const form = page.getByTestId('mint-api-token-form');
    await form.getByLabel('Name').fill('Run history reports');
    await submitRedirectingForm(
        page,
        form.getByRole('button', { name: 'Create token' }),
        '/account/api-tokens',
    );
    const secret = page.getByTestId('minted-api-token-value');
    await expect(secret).toBeVisible();
    const token = (await secret.textContent())!.trim();
    const output =
        '<img src=x onerror="window.runOutputExecuted=true">\n' +
        'Long output '.repeat(200);
    const reports = [
        { exitCode: 0, failureReason: null, outcome: 'Succeeded' },
        { exitCode: 1, failureReason: null, outcome: 'Failed' },
        {
            exitCode: null,
            failureReason: 'Worker executable unavailable',
            outcome: 'Never started',
        },
    ];
    const ids: string[] = [];
    for (const [index, report] of reports.entries()) {
        const response = await page.request.post(
            `/api/projects/${projectId}/worker-runs`,
            {
                headers: { Authorization: `Bearer ${token}` },
                data: {
                    bridgeId: crypto.randomUUID(),
                    sessionId: crypto.randomUUID(),
                    cardId: crypto.randomUUID(),
                    cardNumber: index + 1,
                    ruleName: 'R'.repeat(100),
                    startedAt: '2026-09-17T12:00:00+00:00',
                    endedAt: '2026-09-17T12:00:21+00:00',
                    exitCode: report.exitCode,
                    failureReason: report.failureReason,
                    output,
                },
            },
        );
        expect(response.status()).toBe(201);
        ids.push((await response.json()).id);
    }
    await page.goto(`/projects/${projectId}/worker-runs`);
    for (const [index, report] of reports.entries()) {
        const row = page.locator(`[data-worker-run-id="${ids[index]}"]`);
        await expect(row.locator('.lp-status-chip')).toHaveText(report.outcome);
        const toggle = row.locator('summary');
        await toggle.focus();
        await toggle.press('Enter');
        await expect(row.locator('pre')).toHaveText(output);
        await expect(row.locator('img')).toHaveCount(0);
        await toggle.press('Enter');
    }
    for (const fontSize of ['100%', '200%']) {
        await page.evaluate((size) => {
            document.documentElement.style.fontSize = size;
        }, fontSize);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            const list = page.locator('.lp-worker-run-list');
            const bounds = await list.boundingBox();
            expect(bounds).not.toBeNull();
            expect(bounds!.x).toBeGreaterThanOrEqual(0);
            expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
            for (const rule of await page
                .locator('.lp-worker-run__rule')
                .all()) {
                expect(
                    await rule.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                ).toBeLessThanOrEqual(1);
            }
            const bridgeFilter = page.locator('#worker-run-bridge');
            const bridgeBounds = (await bridgeFilter.boundingBox())!;
            const formBounds = (await page
                .locator('.lp-filter-form')
                .boundingBox())!;
            expect(bridgeBounds.x + bridgeBounds.width).toBeLessThanOrEqual(
                formBounds.x + formBounds.width,
            );
            const outcome = page
                .locator('.lp-worker-run .lp-status-chip')
                .first();
            const badge = await outcome.evaluate((element) => {
                const style = getComputedStyle(element);
                return {
                    height: element.getBoundingClientRect().height,
                    contentHeight: [
                        'lineHeight',
                        'paddingTop',
                        'paddingBottom',
                        'borderTopWidth',
                        'borderBottomWidth',
                    ].reduce(
                        (sum, property) =>
                            sum +
                            parseFloat(
                                style[
                                    property as keyof CSSStyleDeclaration
                                ] as string,
                            ),
                        0,
                    ),
                };
            });
            expect(badge.height).toBeCloseTo(badge.contentHeight, 1);
            await outcome.scrollIntoViewIfNeeded();
            await expect(outcome).toBeInViewport({ ratio: 1 });
            await page.screenshot({
                path: testInfo.outputPath(`runs-${width}-${fontSize}.png`),
                animations: 'disabled',
            });
        }
    }
});
