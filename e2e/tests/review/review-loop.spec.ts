/**
 * End-to-end test for the full document review loop:
 * seed a user + document → select text → add a comment → request changes → verify persistence.
 *
 * User creation uses a dev-only endpoint (/dev/register-and-verify) that registers and
 * immediately marks the email as verified, bypassing the email confirmation flow.
 *
 * Document seeding uses a dev-only POST endpoint (/dev/seed/document).
 * Quote read-back uses a dev-only GET endpoint (/dev/review/{id}/state).
 * The status badge is asserted on the project dashboard (/projects/{projectId}/documents) after verdict submission.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

const KNOWN_PHRASE = 'sample phrase for selection';
const DOCUMENT_MARKDOWN = `# E2E Review Test Document

This paragraph contains a ${KNOWN_PHRASE} in this review.`;

const COMMENT_BODY = 'This is an e2e test comment on the selected text.';
const REPLY_BODY = 'This is an e2e reply to the comment.';

/** Register a user via the dev endpoint and immediately mark them as verified. */
async function devRegisterAndVerify(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: {
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
    // A freshly-registered user owns no projects and hasn't completed the
    // first-run wizard yet, so HomeController lands them on it (seedDocument,
    // called right after this, creates the project the wizard would have).
    await expect(page).toHaveURL('/welcome');
}

/**
 * Seed a document via the dev-only endpoint. Returns the document and its
 * owning project UUIDs. Must be called while the page session is authenticated.
 */
async function seedDocument(
    page: Page,
): Promise<{ documentId: string; projectId: string }> {
    const response = await page.request.post('/dev/seed/document', {
        form: {
            title: 'E2E Review Test Document',
            markdown: DOCUMENT_MARKDOWN,
        },
    });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return {
        documentId: body.documentId as string,
        projectId: body.projectId as string,
    };
}

/**
 * Drive text selection inside [data-comment-anchor-target="doc"] by
 * programmatically setting a DOM Range over KNOWN_PHRASE, then dispatching
 * mouseup on the .lp-review-doc element so the Stimulus controller computes
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

        // Dispatch mouseup from inside the doc target (the controller ignores
        // mouseups whose target is outside it) so onDocMouseup fires; it bubbles
        // up to the .lp-review-doc listener with target === docEl.
        docEl.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    }, phrase);
}

test('full review loop: comment, request changes, reload persistence', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);

    const email = `e2e+review+${RUN}@example.com`;
    const password = 'E2eReviewLoop1!';

    // Step 1: Register and verify a fresh user for this test run via the dev endpoint
    // (bypasses email confirmation — mailpit is not accessible via traefik in this project).
    await devRegisterAndVerify(page, email, password);

    // Step 2: Log in.
    await login(page, email, password);

    // Step 3: Seed a fresh document for this test run.
    const { documentId, projectId } = await seedDocument(page);
    const reviewUrl = `/projects/${projectId}/documents/${documentId}/review`;
    const dashboardUrl = `/projects/${projectId}/documents`;

    // Step 4: Open the review page.
    await page.goto(reviewUrl);
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible();

    // Step 5: Select the known phrase. Selecting text now shows a floating
    // toolbar (not the composer, so selecting/copying isn't hijacked); clicking
    // "Comment" opens the composer. Use exact match so it doesn't also pick up
    // the sidebar's "Add comment" (untargeted) button.
    await selectKnownPhrase(page, KNOWN_PHRASE);
    await expect(
        page.locator('[data-comment-anchor-target="toolbar"]'),
    ).toBeVisible({ timeout: 5000 });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await expect(
        page.locator('[data-comment-anchor-target="composer"]'),
    ).toBeVisible({ timeout: 5000 });

    // Step 6: Fill the comment body and post.
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill(COMMENT_BODY);

    await page.getByRole('button', { name: 'Post' }).click();

    // The composer is a plain form submitted through Turbo; the controller returns
    // a Turbo Stream that replaces the thread list in place (no reload). The
    // composer hides on success and the new thread appears in the sidebar.
    await expect(
        page.locator('[data-comment-anchor-target="composer"]'),
    ).toBeHidden({ timeout: 10000 });

    // Step 7: Assert the comment appears in the sidebar.
    const commentBody = page.locator('.lp-comment-body').first();
    await expect(commentBody).toBeVisible({ timeout: 10000 });
    await expect(commentBody).toContainText(COMMENT_BODY);

    // Step 7b: the thread renders the anchored document text as a quote.
    await expect(page.locator('.lp-comment-quote').first()).toContainText(
        KNOWN_PHRASE,
    );

    // Step 8: Read the review payload back and assert the quote is the selected
    // phrase. The endpoint reports the quote widened to word edges rather than the
    // stored one, and KNOWN_PHRASE is whitespace-delimited in the document, so this
    // proves the anchor round-tripped through capture, storage and reporting without
    // picking up neighbouring words — not that the stored quote is byte-identical.
    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as {
        comments: Array<{ quote: string; body: string }>;
        storedAnchors: Array<{
            quote: string;
            prefix: string;
            suffix: string;
        }>;
    };
    expect(state.comments).toHaveLength(1);
    expect(state.comments[0].quote).toBe(KNOWN_PHRASE);

    // Step 8a: Assert the stored prefix and suffix, not just the quote. This is
    // the only assertion in the suite that can fail on anchor corruption
    // occurring *before* AnchorService sees the data — every unit test builds an
    // Anchor by hand, so all of them start from data that is already correct.
    //
    // Boundary whitespace is the specific thing at stake. The document reads
    // "...contains a <quote> in this review.", so the captured prefix must end
    // with a space and the suffix must begin with one. Symfony's form `trim`
    // option defaults to true and HiddenType inherits it, which silently stripped
    // exactly these characters until 2026-08-02; contextScore() compares the last
    // 8 characters of the prefix against the document, so a trimmed fingerprint
    // could never match and context disambiguation scored zero for every
    // selection next to whitespace — which is nearly all of them.
    //
    // The widened quote above cannot catch this: it is identical either way.
    expect(state.storedAnchors).toHaveLength(1);
    const anchor = state.storedAnchors[0];
    expect(anchor.quote).toBe(KNOWN_PHRASE);
    expect(anchor.prefix).toMatch(/ $/);
    expect(anchor.prefix).toContain('contains a');
    expect(anchor.suffix).toMatch(/^ /);
    expect(anchor.suffix).toContain('in this review');

    // Step 8b: Reply to the comment. Reply is a plain form submitted through Turbo;
    // the controller returns a Turbo Stream (HTTP 200, turbo-stream content type)
    // that replaces the thread in place — no page reload. Asserting both status and
    // content type guards the whole path: CSRF (the eager submit listener runs the
    // double-submit dance), the {id:comment} entity mapping, and the stream wiring
    // (a wrong content type makes Turbo silently no-op).
    const replyResponsePromise = page.waitForResponse(
        (r) => r.url().includes('/reply') && r.request().method() === 'POST',
    );
    await page
        .locator('.lp-comment-reply-form textarea')
        .first()
        .fill(REPLY_BODY);
    await page.getByRole('button', { name: 'Reply' }).click();
    const replyResponse = await replyResponsePromise;
    expect(replyResponse.status()).toBe(200);
    expect(replyResponse.headers()['content-type']).toContain('turbo-stream');

    // The reply appears in place as a .lp-comment--reply (Turbo replaced the thread).
    await expect(
        page.locator('.lp-comment--reply .lp-comment-body'),
    ).toContainText(REPLY_BODY, { timeout: 10000 });

    // Step 8c: Resolve the thread. Same form + Turbo Stream path; the thread is
    // replaced in place and gains the resolved modifier.
    const resolveResponsePromise = page.waitForResponse(
        (r) => r.url().includes('/resolve') && r.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Resolve' }).click();
    const resolveResponse = await resolveResponsePromise;
    expect(resolveResponse.status()).toBe(200);
    expect(resolveResponse.headers()['content-type']).toContain('turbo-stream');

    await expect(page.locator('.lp-comment-thread--resolved')).toBeVisible({
        timeout: 10000,
    });

    // Step 9: Submit the "Request changes" verdict.
    await page.getByRole('button', { name: 'Request changes' }).click();

    // The form POSTs (Turbo Drive) and redirects back to the *same* review URL,
    // so "doc is visible" proves nothing (it never went away). Wait for the
    // success flash, which only renders after the verdict is persisted — otherwise
    // navigating to the dashboard races the POST and reads a stale "In review" badge.
    await expect(page.locator('.lp-flash--success')).toBeVisible({
        timeout: 10000,
    });

    // Step 10: Assert the status badge on the dashboard (scoped to THIS document's row).
    await page.goto(dashboardUrl);
    const badge = page.locator(`[data-document-id="${documentId}"] .lp-badge`);
    await expect(badge).toBeVisible({ timeout: 5000 });
    await expect(badge).toHaveText('Changes requested');

    // Step 11: Reload the review page and assert the comment is still present.
    await page.goto(reviewUrl);
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible();
    const persistedCommentBody = page.locator('.lp-comment-body').first();
    await expect(persistedCommentBody).toBeVisible({ timeout: 5000 });
    await expect(persistedCommentBody).toContainText(COMMENT_BODY);

    // Step 12: Re-check the status is still "Changes requested" on the dashboard.
    await page.goto(dashboardUrl);
    const reloadedBadge = page.locator(
        `[data-document-id="${documentId}"] .lp-badge`,
    );
    await expect(reloadedBadge).toHaveText('Changes requested');

    // Step 13: Delete the (resolved) comment. Delete is a fieldless form guarded
    // by a data-turbo-confirm dialog; accept it, then the Turbo Stream re-renders
    // the thread list without the comment.
    await page.goto(reviewUrl);
    await expect(page.locator('.lp-comment-thread')).toHaveCount(1, {
        timeout: 5000,
    });
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(page.locator('.lp-comment-thread')).toHaveCount(0, {
        timeout: 10000,
    });

    // Step 14: Reload and confirm the deletion persisted.
    await page.goto(reviewUrl);
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible();
    await expect(page.locator('.lp-comment-thread')).toHaveCount(0);
});

/**
 * The composer's hint promises "⌘⏎ to submit", so the shortcut is part of the
 * contract the UI advertises — a plain click-the-button test would not catch
 * its absence. Playwright's "Meta" maps to Cmd on macOS and to the Windows key
 * elsewhere, so press Control+Enter too: the controller accepts either, and
 * this keeps the spec honest on a Linux CI runner.
 */
test('composer submits on Ctrl/Cmd+Enter', async ({ page }) => {
    await suppressToolbar(page);
    await suppressWidget(page);

    const email = `e2e+reviewkbd+${RUN}@example.com`;
    const password = 'E2eReviewKeys1!';

    await devRegisterAndVerify(page, email, password);
    await login(page, email, password);

    const { documentId, projectId } = await seedDocument(page);
    await page.goto(`/projects/${projectId}/documents/${documentId}/review`);
    await expect(
        page.locator('[data-comment-anchor-target="doc"]'),
    ).toBeVisible();

    await selectKnownPhrase(page, KNOWN_PHRASE);
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    const composer = page.locator('[data-comment-anchor-target="composer"]');
    await expect(composer).toBeVisible({ timeout: 5000 });

    const body = page.locator('[data-comment-anchor-target="composerBody"]');
    await body.fill(COMMENT_BODY);

    // Submit from inside the textarea — the action is bound on the form and
    // relies on keydown bubbling up from the field.
    await body.press('ControlOrMeta+Enter');

    // Same success signal the click path asserts: the Turbo Stream comes back,
    // the composer hides, and the thread appears.
    await expect(composer).toBeHidden({ timeout: 10000 });
    const commentBody = page.locator('.lp-comment-body').first();
    await expect(commentBody).toBeVisible({ timeout: 10000 });
    await expect(commentBody).toContainText(COMMENT_BODY);

    // Guard against a double submit: the keydown must not also trigger the
    // form's default newline-then-submit behaviour.
    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as {
        comments: Array<{ quote: string; body: string }>;
    };
    expect(state.comments).toHaveLength(1);
});
