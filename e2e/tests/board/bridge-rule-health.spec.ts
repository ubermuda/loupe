/**
 * Browser coverage for bridge rule health. A report is sent through the real
 * endpoint with an agent token from the device flow, so no bridge runs.
 * The board then shows a banner for the dead rule, and the rename and delete
 * dialogs of a watched column warn before they save.
 */

import {
    test,
    expect,
    type APIRequestContext,
    type Page,
} from '@playwright/test';
import { agentAccessToken, suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eBridgeRules1!';
const COLUMN = '[data-board-columns-target="column"]';

async function setBoardFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'board.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function registerAndLogin(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Rules User', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

async function seedProject(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Rules Project', markdown: '# Rules' },
    });
    expect(response.status()).toBe(201);

    return (await response.json()).projectId as string;
}

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1600, height: 900 },
});

// The flag is global, so it goes back off for the specs that run after this one.
test.afterAll(async ({ request }) => {
    await setBoardFlag(request, false);
});

test('connection health remains accessible at enlarged text sizes', async ({
    page,
}, testInfo) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await registerAndLogin(page, `e2e+bridge-health+${RUN}@example.com`);
    const projectId = await seedProject(page);
    const token = await agentAccessToken(page);
    const bridgeId = crypto.randomUUID();
    const heartbeat = await page.request.put(
        `/api/bridges/${bridgeId}/heartbeat`,
        {
            headers: { Authorization: `Bearer ${token}` },
            data: { projects: [projectId], cliVersion: 'a'.repeat(100) },
        },
    );
    expect(heartbeat.status()).toBe(200);
    await page.goto(`/projects/${projectId}/agents`);
    const connection = page.locator(`[data-agent-connection-id="${bridgeId}"]`);
    await expect(connection).toBeVisible();
    expect(await connection.ariaSnapshot()).toContain('Healthy');
    for (const fontSize of ['100%', '200%']) {
        await page.evaluate((size) => {
            document.documentElement.style.fontSize = size;
        }, fontSize);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await expect
                .poll(() =>
                    page.evaluate(
                        () =>
                            document.documentElement.scrollWidth -
                            window.innerWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            await expect
                .poll(() =>
                    connection.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            for (const action of await page
                .locator('.lp-orchestration-page a')
                .all()) {
                await action.scrollIntoViewIfNeeded();
                const geometry = await action.evaluate((element) => ({
                    bounds: element.getBoundingClientRect().toJSON(),
                    parent: element
                        .parentElement!.getBoundingClientRect()
                        .toJSON(),
                    viewport: [innerWidth, innerHeight],
                }));
                await expect(action, JSON.stringify(geometry)).toBeInViewport({
                    ratio: 1,
                });
                expect(geometry.bounds.left).toBeGreaterThanOrEqual(
                    geometry.parent.left,
                );
                expect(geometry.bounds.right).toBeLessThanOrEqual(
                    geometry.parent.right,
                );
            }
            await page.screenshot({
                path: testInfo.outputPath(`agents-${width}-${fontSize}.png`),
                animations: 'disabled',
                fullPage: true,
            });
            await connection.locator('footer').scrollIntoViewIfNeeded();
            await page.screenshot({
                path: testInfo.outputPath(
                    `agents-details-${width}-${fontSize}.png`,
                ),
                animations: 'disabled',
            });
        }
    }
});

test('a dead rule shows a banner and a watched column warns before a rename or a delete', async ({
    page,
}, testInfo) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await setBoardFlag(page.request, true);
    await registerAndLogin(page, `e2e+bridge-rules+${RUN}@example.com`);

    const projectId = await seedProject(page);
    const token = await agentAccessToken(page);
    expect(token).not.toBe('');

    const report = await page.request.put(
        `/api/projects/${projectId}/bridges/${crypto.randomUUID()}/rules`,
        {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
            },
            data: {
                rules: [
                    {
                        name: 'plan',
                        on: 'board.card_moved',
                        columns: ['ready'],
                        state: 'dead',
                        reason: 'column_renamed',
                    },
                    {
                        name: 'review',
                        on: 'board.card_moved',
                        columns: ['in-progress'],
                        state: 'live',
                        reason: null,
                    },
                    {
                        name: 'R'.repeat(100),
                        on: 'board.card_moved',
                        columns: ['in-progress'],
                        state: 'live',
                        reason: null,
                    },
                ],
            },
        },
    );
    expect(report.status()).toBe(204);

    await page.goto(`/projects/${projectId}/board`);
    const banner = page.getByTestId('bridge-rules-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('A bridge reports a dead rule');
    await expect(banner.locator('[data-bridge-rule="plan"]')).toContainText(
        'column_renamed',
    );
    await expect(banner).not.toContainText('review');

    const column = page.locator(`${COLUMN}[data-column-slug="in-progress"]`);
    await column.locator('.lp-board__column-menu-trigger').click();
    await column.getByRole('button', { name: 'Rename' }).click();
    const rename = page.locator('dialog[open]');
    await expect(rename.locator('.lp-board__rule-warning')).toContainText(
        'A bridge rule watches this column.',
    );
    await rename.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);

    await column.getByRole('button', { name: 'Delete column' }).click();
    const remove = page.locator('dialog[open]');
    await expect(remove.locator('.lp-board__rule-warning')).toContainText(
        'Deleting it removes its slug',
    );
    await remove.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.goto(`/projects/${projectId}/rules`);
    const rows = page.locator('[data-rule-name]');
    await expect(rows).toHaveCount(3);
    await page
        .getByRole('searchbox', { name: 'Find a rule', exact: true })
        .fill('REVIEW');
    await expect(rows).toHaveCount(1);
    await expect(rows).toHaveAttribute('data-rule-name', 'review');
    await page.reload();
    await expect(rows).toHaveCount(1);
    await page
        .getByRole('searchbox', { name: 'Find a rule', exact: true })
        .fill('missing');
    await expect(
        page.getByRole('heading', { name: 'No matching rules', exact: true }),
    ).toBeVisible();
    await expect(rows).toHaveCount(0);
    await expect(page.locator('[data-rule-live-count]')).toHaveText(
        '2 live rules',
    );
    await page.getByRole('link', { name: 'Clear', exact: true }).click();
    await expect(rows).toHaveCount(3);
    for (const fontSize of ['100%', '200%']) {
        await page.evaluate((size) => {
            document.documentElement.style.fontSize = size;
        }, fontSize);
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            await expect
                .poll(() =>
                    page.evaluate(
                        () =>
                            document.documentElement.scrollWidth -
                            window.innerWidth,
                    ),
                )
                .toBeLessThanOrEqual(1);
            await expect
                .poll(() =>
                    rows.evaluateAll((elements) =>
                        Math.max(
                            0,
                            ...elements.flatMap((element) => {
                                const card = element.getBoundingClientRect();
                                return Array.from(
                                    element.querySelectorAll('*'),
                                ).flatMap((child) => {
                                    const bounds =
                                        child.getBoundingClientRect();
                                    return [
                                        bounds.right - card.right,
                                        card.left - bounds.left,
                                    ];
                                });
                            }),
                        ),
                    ),
                )
                .toBeLessThanOrEqual(1);
            const input = page.getByRole('searchbox', {
                name: 'Find a rule',
                exact: true,
            });
            // The search submits as the reader types, so it has no button.
            await input.focus();
            await expect(input).toBeInViewport({ ratio: 1 });
            await page.screenshot({
                path: testInfo.outputPath(`rules-${width}-${fontSize}.png`),
                animations: 'disabled',
                fullPage: true,
            });
        }
    }
});
