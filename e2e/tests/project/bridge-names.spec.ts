import { expect } from '@playwright/test';
import { agentAccessToken, createTest, suppressWidget } from '../fixtures';

const test = createTest({
    email: `e2e-bridge-names-${Date.now()}@example.com`,
    password: 'e2e_password_123',
});

test('a bridge shows its name, and a second claim of the name shows a clash', async ({
    page,
}) => {
    await suppressWidget(page);
    const seed = await page.request.post('/dev/seed/document', {
        form: { title: 'Bridge names', markdown: '# Names' },
    });
    expect(seed.status()).toBe(201);
    const { projectId } = await seed.json();
    const token = await agentAccessToken(page);
    const headers = { Authorization: `Bearer ${token}` };
    const heartbeat = async (bridgeId: string, name?: string) => {
        const response = await page.request.put(
            `/api/bridges/${bridgeId}/heartbeat`,
            {
                headers,
                data: {
                    projects: [projectId],
                    cliVersion: '1.0.0',
                    ...(name === undefined ? {} : { name }),
                },
            },
        );
        expect(response.status()).toBe(200);
    };
    const report = async (bridgeId: string) => {
        const response = await page.request.put(
            `/api/projects/${projectId}/worker-runs/${crypto.randomUUID()}`,
            {
                headers,
                data: {
                    bridgeId,
                    at: '2026-10-02T10:00:00+00:00',
                    ruleName: 'implement',
                    cardId: crypto.randomUUID(),
                    cardNumber: 7,
                    cardColumn: 'implementation',
                    state: 'queued',
                },
            },
        );
        expect(response.status()).toBe(201);
    };

    const holder = crypto.randomUUID();
    const claimer = crypto.randomUUID();
    const unnamed = crypto.randomUUID();
    await heartbeat(holder, 'homelab');
    await heartbeat(claimer, 'homelab');
    await heartbeat(unnamed);

    await page.goto(`/projects/${projectId}/agents`);
    const card = (bridgeId: string) =>
        page.locator(`[data-agent-connection-id="${bridgeId}"]`);
    await expect(card(holder).locator('.lp-agent-card__name')).toHaveText(
        'homelab',
    );
    await expect(card(holder).locator('[data-agent-name-clash]')).toHaveCount(
        0,
    );
    await expect(card(claimer).locator('.lp-agent-card__name')).toHaveText(
        claimer.slice(-12),
    );
    const clash = card(claimer).locator('[data-agent-name-clash]');
    await expect(clash).toBeVisible();
    await expect(clash).toContainText('Name already in use');
    await expect(clash).toContainText('homelab');
    await expect(card(unnamed).locator('.lp-agent-card__name')).toHaveText(
        unnamed.slice(-12),
    );
    await expect(card(unnamed).locator('[data-agent-name-clash]')).toHaveCount(
        0,
    );

    await report(holder);
    await report(unnamed);
    await page.goto(`/projects/${projectId}/worker-runs`);
    const filter = page.locator('#worker-run-bridge');
    await expect(filter.locator(`option[value="${holder}"]`)).toHaveText(
        'homelab',
    );
    await expect(filter.locator(`option[value="${unnamed}"]`)).toHaveText(
        unnamed.slice(-12),
    );
});
