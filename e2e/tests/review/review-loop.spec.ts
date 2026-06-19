/**
 * End-to-end test for the full document review loop:
 * seed a user + document → select text → add a comment → request changes → verify persistence.
 *
 * User creation uses a dev-only endpoint (/dev/register-and-verify) that registers and
 * immediately marks the email as verified, bypassing the email confirmation flow.
 *
 * Document seeding uses a dev-only POST endpoint (/dev/seed/document).
 * Quote read-back uses a dev-only GET endpoint (/dev/review/{id}/state).
 * The status badge is asserted on the dashboard (/documents) after verdict submission.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar } from '../fixtures';

const RUN = Date.now();

const KNOWN_PHRASE = 'sample phrase for selection';
const DOCUMENT_MARKDOWN = `# E2E Review Test Document

This paragraph contains a ${KNOWN_PHRASE} in this review.`;

const COMMENT_BODY = 'This is an e2e test comment on the selected text.';

/** Register a user via the dev endpoint and immediately mark them as verified. */
async function devRegisterAndVerify(
    page: Page,
    email: string,
    username: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: {
            username,
            fullName: 'E2E Reviewer',
            email,
            password,
        },
    });
    expect(response.status()).toBe(200);
}

/** Log in as a user via the login form and wait for home page. */
async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/');
}

/**
 * Seed a document via the dev-only endpoint. Returns the document UUID.
 * Must be called while the page session is authenticated.
 */
async function seedDocument(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: {
            title: 'E2E Review Test Document',
            markdown: DOCUMENT_MARKDOWN,
        },
    });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return body.documentId as string;
}

/**
 * Drive text selection inside [data-comment-anchor-target="doc"] by
 * programmatically setting a DOM Range over KNOWN_PHRASE, then dispatching
 * mouseup on the .bp-review-doc element so the Stimulus controller computes
 * the offset and shows the composer.
 */
async function selectKnownPhrase(page: Page, phrase: string): Promise<void> {
    await page.evaluate((phraseToSelect: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (!docEl) throw new Error('doc target not found');

        // Walk text nodes to find the one containing our phrase.
        const walker = document.createTreeWalker(
            docEl,
            NodeFilter.SHOW_TEXT,
            null,
        );
        let textNode: Text | null = null;
        let nodeOffset = 0;
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const idx = node.textContent?.indexOf(phraseToSelect) ?? -1;
            if (idx !== -1) {
                textNode = node;
                nodeOffset = idx;
                break;
            }
            node = walker.nextNode() as Text | null;
        }

        if (!textNode) {
            throw new Error(
                `Phrase "${phraseToSelect}" not found in any text node inside [data-comment-anchor-target="doc"]`,
            );
        }

        // Set the selection range over the phrase.
        const range = document.createRange();
        range.setStart(textNode, nodeOffset);
        range.setEnd(textNode, nodeOffset + phraseToSelect.length);

        const sel = window.getSelection();
        if (!sel) throw new Error('No selection object');
        sel.removeAllRanges();
        sel.addRange(range);

        // Dispatch mouseup on the bp-review-doc element so the Stimulus
        // comment-anchor controller's onDocMouseup handler fires.
        const reviewDoc = document.querySelector('.bp-review-doc');
        if (!reviewDoc) throw new Error('.bp-review-doc not found');
        reviewDoc.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    }, phrase);
}

test('full review loop: comment, request changes, reload persistence', async ({
    page,
}) => {
    await suppressToolbar(page);

    const email = `e2e+review+${RUN}@example.com`;
    const username = `e2erev${RUN}`;
    const password = 'E2eReviewLoop1!';

    // Step 1: Register and verify a fresh user for this test run via the dev endpoint
    // (bypasses email confirmation — mailpit is not accessible via traefik in this project).
    await devRegisterAndVerify(page, email, username, password);

    // Step 2: Log in.
    await login(page, email, password);

    // Step 3: Seed a fresh document for this test run.
    const documentId = await seedDocument(page);
    const reviewUrl = `/documents/${documentId}/review`;

    // Step 4: Open the review page.
    await page.goto(reviewUrl);
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible();

    // Step 5: Select the known phrase and wait for the composer to appear.
    await selectKnownPhrase(page, KNOWN_PHRASE);
    await expect(
        page.locator('[data-comment-anchor-target="composer"]'),
    ).toBeVisible({ timeout: 5000 });

    // Step 6: Fill the comment body and submit.
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill(COMMENT_BODY);

    await page.getByRole('button', { name: 'Comment' }).click();

    // On success (HTTP 201) the Stimulus controller calls window.location.reload().
    // The page reloads to the same URL. Wait for the sidebar to show the comment
    // (the composer disappears and .bp-comment-body appears after the reload).
    // We assert the doc is visible first to confirm the reload completed.
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible({ timeout: 10000 });

    // Step 7: Assert the comment appears in the sidebar.
    const commentBody = page.locator('.bp-comment-body').first();
    await expect(commentBody).toBeVisible({ timeout: 10000 });
    await expect(commentBody).toContainText(COMMENT_BODY);

    // Step 8: Assert the stored quote equals exactly the selected phrase
    // via the dev read-back endpoint. This is the critical anchor reconciliation
    // assertion from Task 14 — it would catch a subtly-wrong offset.
    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as {
        comments: Array<{ quote: string; body: string }>;
    };
    expect(state.comments).toHaveLength(1);
    expect(state.comments[0].quote).toBe(KNOWN_PHRASE);

    // Step 9: Submit the "Request changes" verdict.
    await page.getByRole('button', { name: 'Request changes' }).click();

    // The form POSTs (Turbo Drive) and redirects back to the review page.
    // Assert the doc is visible to confirm the redirect completed.
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible({ timeout: 10000 });

    // Step 10: Assert the status badge on the dashboard.
    await page.goto('/documents');
    const badge = page.locator('.bp-badge').first();
    await expect(badge).toBeVisible({ timeout: 5000 });
    await expect(badge).toHaveText('Changes requested');

    // Step 11: Reload the review page and assert the comment is still present.
    await page.goto(reviewUrl);
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible();
    const persistedCommentBody = page.locator('.bp-comment-body').first();
    await expect(persistedCommentBody).toBeVisible({ timeout: 5000 });
    await expect(persistedCommentBody).toContainText(COMMENT_BODY);

    // Step 12: Re-check the status is still "Changes requested" on the dashboard.
    await page.goto('/documents');
    const reloadedBadge = page.locator('.bp-badge').first();
    await expect(reloadedBadge).toHaveText('Changes requested');
});
