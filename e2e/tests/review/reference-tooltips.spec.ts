/**
 * A mention of a defined ID, such as R1, reminds the reader what it stands for.
 *
 * The plan defines R1 in a numbered list. The rollout mentions R1 and points at
 * the plan, so its tooltip names the plan as the source. The reference comes
 * from the MCP document_set_references tool, because the app has no form for it.
 */

import { expect, type Page } from '@playwright/test';
import {
    hubStubbedTest as test,
    accessToken,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';

test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'E2eReferenceTooltips1!';
const DEFINITION = 'Keep the cache warm.';
const TRAILING_PHRASE = 'trailing passage for a comment';

const PLAN_MARKDOWN = `# Cache plan

1. **R1: ${DEFINITION}** The board reads it first.

The design follows R1 closely, with a ${TRAILING_PHRASE} after it.`;

const ROLLOUT_MARKDOWN = `# Rollout

The rollout keeps R1 in mind.`;

const DOC = '[data-comment-anchor-target="doc"]';
const TOOLTIP = '#reference-tooltip';

interface Seeded {
    documentId: string;
    projectId: string;
}

async function seedDocument(
    page: Page,
    title: string,
    markdown: string,
): Promise<Seeded> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title, markdown },
    });
    expect(response.status()).toBe(201);
    const body = await response.json();

    return { documentId: body.documentId, projectId: body.projectId };
}

async function setReferences(
    page: Page,
    projectId: string,
    documentId: string,
    references: string[],
): Promise<void> {
    const token = await accessToken(page, 'mcp', projectId);
    const headers: Record<string, string> = {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
    };
    const initialize = await page.request.post('/mcp', {
        headers,
        data: {
            jsonrpc: '2.0',
            id: 1,
            method: 'initialize',
            params: {
                protocolVersion: '2024-11-05',
                capabilities: {},
                clientInfo: { name: 'e2e', version: '1' },
            },
        },
    });
    expect(initialize.ok()).toBeTruthy();
    headers['Mcp-Session-Id'] = initialize.headers()['mcp-session-id'] ?? '';
    await page.request.post('/mcp', {
        headers,
        data: { jsonrpc: '2.0', method: 'notifications/initialized' },
    });

    const response = await page.request.post('/mcp', {
        headers,
        data: {
            jsonrpc: '2.0',
            id: 2,
            method: 'tools/call',
            params: {
                name: 'document_set_references',
                arguments: { documentId, references },
            },
        },
    });
    const answer = await response.json();
    expect(answer.result?.isError, JSON.stringify(answer)).toBeFalsy();
}

async function signIn(page: Page, email: string): Promise<void> {
    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Reader', email, password: PASSWORD },
    });
    expect(registered.status()).toBe(200);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

function reviewUrl(seeded: Seeded): string {
    return `/projects/${seeded.projectId}/documents/${seeded.documentId}/review`;
}

async function selectPhrase(page: Page, phrase: string): Promise<void> {
    await page.evaluate((wanted: string) => {
        const doc = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (!doc) throw new Error('doc target not found');
        const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const index = node.textContent?.indexOf(wanted) ?? -1;
            if (index === -1) continue;
            const range = document.createRange();
            range.setStart(node, index);
            range.setEnd(node, index + wanted.length);
            const selection = window.getSelection();
            if (!selection) throw new Error('No selection object');
            selection.removeAllRanges();
            selection.addRange(range);
            doc.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
            return;
        }
        throw new Error(`Phrase "${wanted}" not found in the doc target`);
    }, phrase);
}

let plan: Seeded;
let rollout: Seeded;

test.beforeEach(async ({ page }, testInfo) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
    await signIn(page, `e2e+reference-tooltips+${tag}+${RUN}@example.com`);
    plan = await seedDocument(page, 'Cache plan', PLAN_MARKDOWN);
    rollout = await seedDocument(page, 'Rollout', ROLLOUT_MARKDOWN);
    await setReferences(page, rollout.projectId, rollout.documentId, [
        plan.documentId,
    ]);
});

test('hovering a mention shows its definition and the document it comes from', async ({
    page,
}) => {
    await page.goto(reviewUrl(rollout));
    const mark = page.locator(`${DOC} .lp-ref[data-ref="R1"]`);
    const tooltip = page.locator(TOOLTIP);
    await expect(tooltip).toBeHidden();

    await mark.hover();
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText(DEFINITION);
    await expect(tooltip).toContainText('From Cache plan');
    await expect(
        tooltip.getByRole('link', { name: 'Go to entry' }),
    ).toHaveAttribute('href', `${reviewUrl(plan)}#ref-R1`);

    await page.locator(`${DOC} h1`).hover();
    await expect(tooltip).toBeHidden();
});

test('focusing a mention from the keyboard shows its definition, and Escape hides it', async ({
    page,
}) => {
    await page.goto(reviewUrl(plan));
    const mark = page.locator(`${DOC} .lp-ref[data-ref="R1"]`);
    const tooltip = page.locator(TOOLTIP);

    await mark.focus();
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText(DEFINITION);
    await expect(tooltip).not.toContainText('From');
    await expect(mark).toHaveAttribute('aria-describedby', 'reference-tooltip');

    await page.keyboard.press('Escape');
    await expect(tooltip).toBeHidden();
    await expect(mark).not.toHaveAttribute('aria-describedby', /.+/);
});

test('clicking a mention jumps to its entry, in this document and in the referenced one', async ({
    page,
}) => {
    await page.goto(reviewUrl(plan));
    await page.locator(`${DOC} .lp-ref[data-ref="R1"]`).click();
    await expect(page).toHaveURL(/#ref-R1$/);
    await expect(page.locator(`${DOC} li#ref-R1`)).toBeVisible();

    await page.goto(reviewUrl(rollout));
    await page.locator(`${DOC} .lp-ref[data-ref="R1"]`).click();
    await expect(page).toHaveURL(
        new RegExp(`/documents/${plan.documentId}/review#ref-R1$`),
    );
    await expect(page.locator(`${DOC} li#ref-R1`)).toBeVisible();
});

test('a comment on text after a mention anchors to the words selected', async ({
    page,
}) => {
    await page.goto(reviewUrl(plan));
    await expect(page.locator(`${DOC} .lp-ref[data-ref="R1"]`)).toBeVisible();

    await selectPhrase(page, TRAILING_PHRASE);
    await expect(
        page.locator('[data-comment-anchor-target="toolbar"]'),
    ).toBeVisible({ timeout: coverageScaled(5000) });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    const composer = page.locator('[data-comment-anchor-target="composer"]');
    await expect(composer).toBeVisible({ timeout: coverageScaled(5000) });
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill('Anchored after the mark.');
    await page.getByRole('button', { name: 'Post' }).click();
    await expect(composer).toBeHidden({ timeout: coverageScaled(10000) });

    const response = await page.request.get(
        `/dev/review/${plan.documentId}/state`,
    );
    expect(response.status()).toBe(200);
    const state = await response.json();
    expect(state.comments).toHaveLength(1);
    expect(state.comments[0]).toMatchObject({
        quote: TRAILING_PHRASE,
        orphaned: false,
    });
});

test.describe('on a touch screen', () => {
    test.use({ hasTouch: true });

    test('the first tap on a mention shows its definition, and the second jumps', async ({
        page,
    }) => {
        await page.goto(reviewUrl(plan));
        const mark = page.locator(`${DOC} .lp-ref[data-ref="R1"]`);
        const tooltip = page.locator(TOOLTIP);

        await mark.tap();
        await expect(tooltip).toBeVisible();
        await expect(tooltip).toContainText(DEFINITION);
        await expect(page).not.toHaveURL(/#ref-R1$/);

        await mark.tap();
        await expect(page).toHaveURL(/#ref-R1$/);
    });
});

test('a mention at the bottom of the screen opens its definition above it', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 400 });
    await page.goto(reviewUrl(rollout));
    const mark = page.locator(`${DOC} .lp-ref[data-ref="R1"]`);
    const tooltip = page.locator(TOOLTIP);
    await mark.evaluate((element) => {
        const main = element.closest('.lp-main');
        if (main instanceof HTMLElement) main.style.paddingBottom = '800px';
        element.scrollIntoView({ block: 'end' });
    });

    await mark.hover();
    await expect(tooltip).toBeVisible();
    const box = await tooltip.boundingBox();
    const anchor = await mark.boundingBox();
    if (box === null || anchor === null) throw new Error('No box');
    expect(box.y + box.height).toBeLessThanOrEqual(anchor.y);
});
