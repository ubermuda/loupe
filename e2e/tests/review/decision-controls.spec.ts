import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

/**
 * Browser coverage for reviewer-selectable decision blocks.
 *
 * PHPUnit covers DecisionBlockService and SelectDecisionOptionController; the
 * untested half was the JavaScript. decision_controller.js reads the clicked
 * radio, copies its decision id and option index into a hidden form and calls
 * requestSubmit() — so the Stimulus target names, the `change` delegation and
 * the requestSubmit() choice could all break with a green `just ci`.
 *
 * requestSubmit() is the sharpest of those: `.submit()` fires no submit event,
 * so csrf_protection_controller.js's document-level listener would never run the
 * double-submit and every password-login session would 403 — invisible to a
 * suite that runs no JS.
 */
// Guest by default, and self-registering through the dev endpoints — the same
// shape as review-loop.spec.ts. The shared worker fixture expects to land on
// /projects after login, which a freshly-registered user with no project does
// not, so review specs that seed their own documents drive their own user.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'e2e_password_123';

async function signedInReviewer(page: Page, slug: string): Promise<void> {
    const email = `e2e-decision-${slug}-${RUN}@example.com`;
    const register = await page.request.post('/dev/register-and-verify', {
        form: {
            username: `dec${slug}${RUN}`.slice(0, 30),
            fullName: 'E2E Decisions',
            email,
            password: PASSWORD,
        },
    });
    expect(register.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // No project yet, so HomeController lands on the first-run wizard;
    // seedDocument below creates the project the wizard would have.
    await expect(page).toHaveURL('/welcome');
    await suppressToolbar(page);
    await suppressWidget(page);
}

const DECISION_ID = 'rollout-order';
const OPTION_ONE = 'Ship the migration first';
const OPTION_TWO = 'Ship the reader first';
const BELOW_BLOCK_PHRASE = 'a paragraph well below the decision block';

const MARKDOWN = `# Rollout

Some prose before the decision.

<!-- decision: ${DECISION_ID} -->

1. ${OPTION_ONE}
2. ${OPTION_TWO}

<!-- /decision -->

This is ${BELOW_BLOCK_PHRASE} in the document.`;

async function seedDocument(
    page: Page,
    title: string,
): Promise<{ documentId: string; reviewUrl: string }> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title, markdown: MARKDOWN },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as {
        documentId: string;
        projectId: string;
    };

    return {
        documentId: body.documentId,
        reviewUrl: `/projects/${body.projectId}/documents/${body.documentId}/review`,
    };
}

test('choosing an option records the answer and survives a reload', async ({
    page,
}) => {
    await signedInReviewer(page, 'persist');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — persistence',
    );
    await page.goto(reviewUrl);

    const block = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    await expect(block).toBeVisible();

    // The radios are grouping-only inputs inside the document's stored HTML;
    // nothing posts without the controller copying their values across.
    const secondOption = block.locator(
        'input[type="radio"][data-decision-option]',
    );
    await expect(secondOption).toHaveCount(2);
    await secondOption.nth(1).check();

    // The status region, not the radio's own checked state. The form posts back
    // to the review URL it was submitted from, so toHaveURL resolves instantly,
    // and the radio reads as checked the moment the browser paints it —
    // whether or not the POST ever landed. Reloading on that signal cancels the
    // request in flight and the answer is silently lost.
    await expect(page.locator('#decision-status')).toHaveText(/saved/i, {
        timeout: 15000,
    });

    await page.reload();
    const afterReload = page
        .locator(`[data-decision-id="${DECISION_ID}"]`)
        .locator('input[type="radio"][data-decision-option]');
    await expect(afterReload.nth(1)).toBeChecked();
    await expect(afterReload.nth(0)).not.toBeChecked();
});

test('the answer reaches the review payload', async ({ page }) => {
    await signedInReviewer(page, 'payload');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — payload',
    );
    await page.goto(reviewUrl);

    const radios = page
        .locator(`[data-decision-id="${DECISION_ID}"]`)
        .locator('input[type="radio"][data-decision-option]');
    await radios.nth(0).check();
    await expect(page.locator('#decision-status')).toHaveText(/saved/i, {
        timeout: 15000,
    });

    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as {
        decisions: Array<{ id: string; selected: string | null }>;
    };

    const decision = state.decisions.find((d) => d.id === DECISION_ID);
    expect(decision).toBeDefined();
    // What the agent reads back is the option text, not the index — so this
    // asserts the whole path, not just that a row was written.
    expect(decision?.selected).toBe(OPTION_ONE);
});

test('selecting text below the block still anchors where the reviewer put it', async ({
    page,
}) => {
    await signedInReviewer(page, 'anchor');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — anchoring',
    );
    await page.goto(reviewUrl);

    // The radios live inside [data-comment-anchor-target="doc"], whose
    // textContent must stay identical to DocumentVersion::plainText(). If
    // converting the list to radios changed the text, every offset below the
    // block would shift and this comment would anchor to the wrong span.
    await page.evaluate((phrase: string) => {
        const doc = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (!doc) {
            throw new Error('review pane not found');
        }
        const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        let node: Node | null = walker.nextNode();
        while (node) {
            const index = (node.textContent ?? '').indexOf(phrase);
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + phrase.length);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
                doc.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));

                return;
            }
            node = walker.nextNode();
        }
        throw new Error(`phrase not found: ${phrase}`);
    }, BELOW_BLOCK_PHRASE);

    // The selection raises a toolbar, not the composer; "Comment" opens the
    // composer. exact:true so it does not also match the sidebar's
    // "Add comment" (untargeted) button.
    await expect(
        page.locator('[data-comment-anchor-target="toolbar"]'),
    ).toBeVisible({ timeout: 5000 });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill('Anchored below.');
    await page.getByRole('button', { name: 'Post' }).click();

    await expect(page.locator('.lp-comment-quote').first()).toContainText(
        BELOW_BLOCK_PHRASE,
        { timeout: 15000 },
    );

    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    const state = (await stateRes.json()) as {
        storedAnchors: Array<{ quote: string }>;
    };
    expect(state.storedAnchors).toHaveLength(1);
    expect(state.storedAnchors[0].quote).toBe(BELOW_BLOCK_PHRASE);
});
