import { expect } from '@playwright/test';
import { agentAccessToken, createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-run-history-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

// Ten viewport passes, two font sizes by five widths, each opening and closing
// the attempt drawer. The body alone measures 24s on a CI runner against the
// suite's 30s default, so that default would decide it by luck. Every pass
// asserts layout that holds only at its own width and font size, so the budget
// is the fix rather than dropping a pass.
test.setTimeout(60_000);

test('a resume that gave up shows its place in the series and the run it resumes', async ({
    page,
}) => {
    await suppressWidget(page);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Run series', markdown: '# Series' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const card = {
        subjectType: 'card',
        subjectId: crypto.randomUUID(),
        cardNumber: 7,
    };
    const report = async (runId: string, data: Record<string, unknown>) => {
        const response = await page.request.put(
            `/api/projects/${projectId}/worker-runs/${runId}`,
            {
                headers: { Authorization: `Bearer ${token}` },
                data: {
                    bridgeId,
                    at: '2026-09-23T10:00:00+00:00',
                    workKind: 'implement',
                    ...card,
                    ...data,
                },
            },
        );
        expect(response.status()).toBe(201);

        return (await response.json()).id as string;
    };
    const outcome = {
        sessionId: crypto.randomUUID(),
        startedAt: '2026-09-23T10:00:00+00:00',
        endedAt: '2026-09-23T10:05:00+00:00',
        exitCode: 0,
        hasResult: true,
        resultStatus: 'unfinished',
        output: 'CI still runs',
    };
    const first = crypto.randomUUID();
    await report(first, { state: 'queued' });
    const firstId = await report(first, { state: 'unfinished', ...outcome });
    const resume = crypto.randomUUID();
    await report(resume, {
        state: 'queued',
        continues: first,
    });
    const resumeId = await report(resume, {
        state: 'gave-up',
        ...outcome,
        resultFields: { prUrl: 'https://example.com/pull/1' },
    });

    await page.goto(`/projects/${projectId}/worker-runs`);
    const row = page.locator(`[data-worker-run-id="${resumeId}"]`);
    await expect(
        page.locator(
            `[data-worker-run-id="${resumeId}"] > .lp-worker-run-outcome > .lp-status-chip`,
        ),
    ).toHaveText('Gave up');
    await expect(
        page.locator(
            `[data-worker-run-id="${firstId}"] > .lp-worker-run-outcome > .lp-status-chip`,
        ),
    ).toHaveText('Unfinished');

    await row.getByRole('button', { name: 'View attempt' }).click();
    const drawer = page.getByRole('dialog', { name: 'Run attempt' });
    await expect(drawer).toBeVisible();
    await expect(drawer.locator('[data-worker-run-work-kind]')).toHaveText(
        'implement',
    );
    await expect(
        drawer.locator('[data-worker-run-result-status]'),
    ).toContainText('Unfinished');
    await expect(
        drawer.locator('[data-worker-run-result-fields]'),
    ).toContainText('https://example.com/pull/1');
    await expect(drawer.locator('[data-worker-run-continues]')).toHaveText(
        firstId,
    );
});

test('completed reports retain outcomes and escaped output at enlarged text sizes', async ({
    page,
}) => {
    await suppressWidget(page);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Run history', markdown: '# Runs' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    const token = await agentAccessToken(page);
    const output =
        '<img src=x onerror="window.runOutputExecuted=true">\n' +
        'Long output '.repeat(200);
    const reports = [
        {
            state: 'succeeded',
            exitCode: 0,
            hasResult: true,
            failureReason: null,
            outcome: 'Succeeded',
        },
        {
            state: 'no-result',
            exitCode: 0,
            hasResult: false,
            failureReason: null,
            outcome: 'No result',
        },
        {
            state: 'failed',
            exitCode: 1,
            hasResult: false,
            failureReason: null,
            outcome: 'Failed',
        },
        {
            state: 'not-started',
            exitCode: null,
            hasResult: null,
            failureReason: 'Worker executable unavailable',
            outcome: 'Never started',
        },
    ];
    const ids: string[] = [];
    for (const [index, report] of reports.entries()) {
        const runId = crypto.randomUUID();
        const run = {
            bridgeId: crypto.randomUUID(),
            workKind: 'implement',
            sessionId: crypto.randomUUID(),
            subjectType: 'card',
            subjectId: crypto.randomUUID(),
            cardNumber: index + 1,
            startedAt: '2026-09-17T12:00:00+00:00',
        };
        const put = (data: Record<string, unknown>) =>
            page.request.put(
                `/api/projects/${projectId}/worker-runs/${runId}`,
                {
                    headers: { Authorization: `Bearer ${token}` },
                    data: { ...run, ...data },
                },
            );
        // A worker that started reports running first, as the bridge does.
        if (report.exitCode !== null) {
            const running = await put({ state: 'running', at: run.startedAt });
            expect(running.status()).toBe(201);
        }
        const response = await put({
            state: report.state,
            at: '2026-09-17T12:00:21+00:00',
            endedAt: '2026-09-17T12:00:21+00:00',
            exitCode: report.exitCode,
            hasResult: report.hasResult,
            failureReason: report.failureReason,
            output,
        });
        expect(response.status()).toBe(201);
        ids.push((await response.json()).id);
    }
    await page.goto(`/projects/${projectId}/worker-runs`);
    for (const [index, report] of reports.entries()) {
        const row = page.locator(`[data-worker-run-id="${ids[index]}"]`);
        const chip = page.locator(
            `[data-worker-run-id="${ids[index]}"] > .lp-worker-run-outcome > .lp-status-chip`,
        );
        await expect(chip).toContainText(report.outcome);
        // The row shows a failure reason only in the chip's tooltip.
        await expect(chip.locator('.lp-tooltip')).toHaveText(
            report.failureReason === null ? [] : [report.failureReason],
        );
        await expect(row.locator('img')).toHaveCount(0);
        const open = row.getByRole('button', { name: 'View attempt' });
        await open.focus();
        await open.press('Enter');
        const drawer = page.getByRole('dialog', { name: 'Run attempt' });
        await expect(drawer).toBeVisible();
        await expect(drawer).toContainText(ids[index]);
        await expect(drawer.locator('.lp-status-chip')).toHaveText(
            report.outcome,
        );
        // Running, the outcome and the arrival; a run that never started has no running time.
        await expect(drawer.locator('time')).toHaveCount(
            report.exitCode === null ? 2 : 3,
        );
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
            const list = page.locator('.lp-data-table--runs');
            const bounds = await list.boundingBox();
            expect(bounds).not.toBeNull();
            expect(bounds!.x).toBeGreaterThanOrEqual(0);
            expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
            // The long rule name is cut short, so every cell ends inside its row.
            for (const tableRow of await list
                .locator('.lp-data-table__row')
                .all()) {
                expect(
                    await tableRow.evaluate((element) => {
                        const right = element.getBoundingClientRect().right;
                        return Math.max(
                            ...[...element.children]
                                .filter((child) => child.tagName !== 'DIALOG')
                                .map(
                                    (child) =>
                                        child.getBoundingClientRect().right -
                                        right,
                                ),
                        );
                    }),
                    `${fontSize} at ${width}px`,
                ).toBeLessThanOrEqual(1);
            }
            // Each row is its own grid, so its columns line up with the header only on shared tracks.
            const outcomeHeader = await list
                .locator('.lp-data-table__header > .lp-data-table__cell')
                .nth(1)
                .boundingBox();
            for (const chip of await list
                .locator(
                    '[data-worker-run-id] > .lp-worker-run-outcome > .lp-status-chip',
                )
                .all()) {
                expect(
                    Math.abs((await chip.boundingBox())!.x - outcomeHeader!.x),
                    `${fontSize} at ${width}px`,
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
                .locator(
                    '[data-worker-run-id] > .lp-worker-run-outcome > .lp-status-chip',
                )
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
            expect(badge.height, `${fontSize} at ${width}px`).toBeCloseTo(
                badge.contentHeight,
                1,
            );
            await outcome.scrollIntoViewIfNeeded();
            await expect(outcome, `${fontSize} at ${width}px`).toBeInViewport({
                ratio: 1,
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
