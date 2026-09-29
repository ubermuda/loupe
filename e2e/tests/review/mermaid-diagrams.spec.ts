import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-review-mermaid@example.com',
    password: 'e2e_password_123',
});

// The stub stands in for the CDN build, so no run depends on jsDelivr.
const stubModule = `export default {
    initialize() {},
    async render(id, text) {
        if (text.includes('INVALID')) throw new Error('parse');
        return { svg: '<svg data-testid="stub-diagram"><text>ok</text></svg>' };
    },
};`;

const markdown = [
    '## Flow',
    '',
    '```mermaid',
    'flowchart LR',
    '  GOOD --> B',
    '```',
    '',
    'Between the diagrams.',
    '',
    '```mermaid',
    'INVALID diagram',
    '```',
    '',
].join('\n');

// review.mermaid.enabled ships off. The flag is global, so it goes back off after.
async function setMermaidFlag(
    request: APIRequestContext,
    enabled: boolean,
): Promise<void> {
    const response = await request.post('/dev/e2e/feature-flag', {
        form: { name: 'review.mermaid.enabled', enabled: enabled ? 1 : 0 },
    });
    expect(response.ok()).toBeTruthy();
}

async function openDocument(page: Page): Promise<void> {
    await page.route('**/mermaid@12.0.0/**', (route) =>
        route.fulfill({
            contentType: 'application/javascript',
            headers: { 'Access-Control-Allow-Origin': '*' },
            body: stubModule,
        }),
    );
    const response = await page.request.post('/dev/seed/document', {
        form: { title: `Diagrams ${Date.now()}`, markdown },
    });
    expect(response.ok()).toBeTruthy();
    const { projectId, documentId } = await response.json();
    await page.goto(`/projects/${projectId}/documents/${documentId}/review`);
}

function source(page: Page, marker: string) {
    return page
        .locator('pre')
        .filter({ has: page.locator('code.language-mermaid') })
        .filter({ hasText: marker });
}

test.afterAll(async ({ request }) => {
    await setMermaidFlag(request, false);
});

test('with the flag off, each block shows its source and a notice', async ({
    page,
}) => {
    await setMermaidFlag(page.request, false);
    await openDocument(page);

    await expect(source(page, 'GOOD')).toBeVisible();
    await expect(source(page, 'INVALID')).toBeVisible();
    await expect(
        page
            .locator('.lp-mermaid')
            .getByText('Diagrams are off on this instance'),
    ).toHaveCount(2);
    await expect(page.getByTestId('stub-diagram')).toHaveCount(0);
});

test('with the flag on, a valid block renders and an invalid one keeps its source', async ({
    page,
}) => {
    await setMermaidFlag(page.request, true);
    await openDocument(page);

    const hosts = page.locator('.lp-mermaid');
    await expect(hosts.nth(0).getByTestId('stub-diagram')).toBeVisible();
    await expect(source(page, 'GOOD')).toBeHidden();

    await expect(
        hosts.nth(1).getByText('This diagram could not render'),
    ).toBeVisible();
    await expect(hosts.nth(1).getByTestId('stub-diagram')).toHaveCount(0);
    await expect(source(page, 'INVALID')).toBeVisible();
});

test('the toggle shows and hides the source of a rendered diagram', async ({
    page,
}) => {
    await setMermaidFlag(page.request, true);
    await openDocument(page);

    const host = page.locator('.lp-mermaid').nth(0);
    await expect(host.getByTestId('stub-diagram')).toBeVisible();
    const toggle = host.getByRole('button', { name: 'Show source' });
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');

    await toggle.click();
    await expect(source(page, 'GOOD')).toBeVisible();
    const hide = host.getByRole('button', { name: 'Hide source' });
    await expect(hide).toHaveAttribute('aria-expanded', 'true');

    await hide.click();
    await expect(source(page, 'GOOD')).toBeHidden();
    await expect(
        host.getByRole('button', { name: 'Show source' }),
    ).toBeVisible();
});
