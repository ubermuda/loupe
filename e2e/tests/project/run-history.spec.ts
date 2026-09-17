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
        await expect(
            row.locator('.lp-worker-run__table-row .lp-status-chip'),
        ).toHaveText(report.outcome);
        const toggle = row.locator('summary');
        await toggle.focus();
        await toggle.press('Enter');
        await expect(row.locator('details pre')).toHaveText(output);
        await expect(row.locator('img')).toHaveCount(0);
        await toggle.press('Enter');
        const open = row.getByRole('button', { name: 'View attempt' });
        await open.focus();
        await open.press('Enter');
        const drawer = page.getByRole('dialog', { name: 'Run attempt' });
        await expect(drawer).toBeVisible();
        await expect(drawer).toContainText(ids[index]);
        await expect(drawer.locator('.lp-status-chip')).toHaveText(
            report.outcome,
        );
        await expect(drawer.locator('time')).toHaveCount(3);
        await expect(drawer.locator('pre')).toHaveText(output);
        await expect(drawer).toContainText(
            report.failureReason ?? `exit ${report.exitCode}`,
        );
        await page
            .context()
            .grantPermissions(['clipboard-read', 'clipboard-write']);
        await drawer.getByRole('button', { name: 'Copy output' }).click();
        await expect(drawer.getByRole('status')).toHaveText('Output copied.');
        expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
            output,
        );
        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
        await expect(open).toBeFocused();
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
                .locator('.lp-worker-run__table-row .lp-worker-run__rule')
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
                .locator('.lp-worker-run__table-row .lp-status-chip')
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
            const open = page
                .getByRole('button', { name: 'View attempt' })
                .first();
            await open.click();
            const drawer = page.getByRole('dialog', { name: 'Run attempt' });
            await expect(drawer).toBeVisible();
            await expect(
                drawer.getByRole('button', { name: 'Close', exact: true }),
            ).toBeInViewport({ ratio: 1 });
            await expect(
                drawer.getByRole('button', { name: 'Copy output' }),
            ).toBeInViewport({ ratio: 1 });
            for (const region of [
                '.lp-run-drawer__header',
                '.lp-run-drawer__body',
            ]) {
                expect(
                    await drawer
                        .locator(region)
                        .evaluate(
                            (element) =>
                                element.scrollWidth - element.clientWidth,
                        ),
                ).toBeLessThanOrEqual(1);
            }
            await page.screenshot({
                path: testInfo.outputPath(
                    `run-drawer-${width}-${fontSize}.png`,
                ),
                animations: 'disabled',
            });
            await page.keyboard.press('Escape');
            await expect(drawer).toBeHidden();
            await expect(open).toBeFocused();
        }
    }
    await page.getByRole('button', { name: 'View attempt' }).first().click();
    const drawer = page.getByRole('dialog', { name: 'Run attempt' });
    await page.evaluate(() => {
        Object.defineProperty(navigator.clipboard, 'writeText', {
            configurable: true,
            value: () => Promise.reject(new Error('Clipboard denied')),
        });
    });
    await drawer.getByRole('button', { name: 'Copy output' }).click();
    await expect(drawer.getByRole('status')).toHaveText(
        'The browser could not copy the output. Select and copy the text instead.',
    );
    await expect(drawer.locator('pre')).toHaveText(output);
    await drawer.getByRole('button', { name: 'Close', exact: true }).focus();
    await page.keyboard.press('Tab');
    expect(
        await drawer.evaluate((element) =>
            element.contains(document.activeElement),
        ),
    ).toBe(true);
    const backgroundButton = page
        .getByRole('button', { name: 'View attempt' })
        .first();
    await backgroundButton.focus();
    await expect(backgroundButton).not.toBeFocused();
    expect(
        await drawer.evaluate((element) =>
            element.contains(document.activeElement),
        ),
    ).toBe(true);
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
});
