/**
 * Browser coverage for the document review loop: anchoring a comment to selected
 * text, replying, resolving, deleting, and recording a verdict.
 *
 * Every test drives its own user and document through the dev-only endpoints —
 * /dev/register-and-verify (registers and verifies in one call), /dev/seed/document
 * (seeds the document), /dev/review/{id}/state (reads the stored anchors back).
 * The status badge is asserted on the project dashboard (/projects/{projectId}/documents).
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eReviewLoop1!';

const KNOWN_PHRASE = 'sample phrase for selection';
const DOCUMENT_MARKDOWN = `# E2E Review Test Document

This paragraph contains a ${KNOWN_PHRASE} in this review.`;

const COMMENT_BODY = 'This is an e2e test comment on the selected text.';
const REPLY_BODY = 'This is an e2e reply to the comment.';

const DOC = '[data-comment-anchor-target="doc"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';

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
    // first-run wizard yet, so LandingController lands them on it (seedDocument,
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

interface SeededReview {
    documentId: string;
    projectId: string;
    reviewUrl: string;
    dashboardUrl: string;
}

/**
 * Registers a user, logs in, seeds a document and opens its review page. Runs
 * for every test in this file; tests that need the ids destructure `review`.
 * Each test gets its own user and document, so nothing here can disturb a
 * sibling test's state.
 */
const test = base.extend<{ review: SeededReview }>({
    review: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+review+${tag}+${RUN}@example.com`;

            await devRegisterAndVerify(page, email, PASSWORD);
            await login(page, email, PASSWORD);

            const { documentId, projectId } = await seedDocument(page);
            const reviewUrl = `/projects/${projectId}/documents/${documentId}/review`;

            await page.goto(reviewUrl);
            await expect(page.locator(DOC)).toBeVisible();

            await use({
                documentId,
                projectId,
                reviewUrl,
                dashboardUrl: `/projects/${projectId}/documents`,
            });
        },
        { auto: true },
    ],
});

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

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

/**
 * Select the known phrase and open the composer over it. Selecting text shows a
 * floating toolbar rather than the composer, so selecting/copying isn't hijacked;
 * clicking "Comment" opens the composer. Exact match, so it doesn't also pick up
 * the sidebar's "Add comment" (untargeted) button.
 */
async function openComposer(page: Page): Promise<void> {
    await selectKnownPhrase(page, KNOWN_PHRASE);
    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeVisible({
        timeout: coverageScaled(5000),
    });
}

/**
 * Post a comment anchored to the known phrase, returning once the Turbo Stream
 * has replaced the thread list — the composer hides and the thread is rendered.
 */
async function postComment(page: Page): Promise<void> {
    await openComposer(page);
    await page.locator(COMPOSER_BODY).fill(COMMENT_BODY);
    await page.getByRole('button', { name: 'Post' }).click();
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });
    await expect(page.locator('.lp-comment-body').first()).toBeVisible({
        timeout: coverageScaled(10000),
    });
}

test('posting a comment disables the submitter and renders the thread in the sidebar', async ({
    page,
}) => {
    await openComposer(page);
    await page.locator(COMPOSER_BODY).fill(COMMENT_BODY);

    // Turbo disables the form's submitter for the length of the request, which
    // is what stops a second click posting the same comment twice. It can only
    // do that if the button reaches requestSubmit() as the submitter, so hold
    // the POST open long enough to see it.
    let held = false;
    await page.route('**/comments', async (route) => {
        // Only the first one, and never unrouted: tearing the route down while
        // its handler is still sleeping aborts the request it is holding.
        if (!held) {
            held = true;
            await new Promise((resolve) => setTimeout(resolve, 1500));
        }
        await route.continue();
    });
    const post = page.getByRole('button', { name: 'Post' });
    await post.click();
    await expect(post).toBeDisabled({ timeout: 1000 });

    // The composer is a plain form submitted through Turbo; the controller returns
    // a Turbo Stream that replaces the thread list in place (no reload). The
    // composer hides on success and the new thread appears in the sidebar.
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });

    const commentBody = page.locator('.lp-comment-body').first();
    await expect(commentBody).toBeVisible({ timeout: coverageScaled(10000) });
    await expect(commentBody).toContainText(COMMENT_BODY);

    // The thread renders the anchored document text as a quote.
    await expect(page.locator('.lp-comment-quote').first()).toContainText(
        KNOWN_PHRASE,
    );
});

test('the stored anchor keeps the quote and the whitespace around it', async ({
    page,
    review,
}) => {
    await postComment(page);

    // The endpoint reports the quote widened to word edges rather than the stored
    // one, and KNOWN_PHRASE is whitespace-delimited in the document, so this proves
    // the anchor round-tripped through capture, storage and reporting without
    // picking up neighbouring words — not that the stored quote is byte-identical.
    const stateRes = await page.request.get(
        `/dev/review/${review.documentId}/state`,
    );
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

    // The only assertion that can fail on anchor corruption occurring before
    // AnchorService sees the data — every unit test builds an Anchor by hand.
    // Boundary whitespace is what is at stake: the form `trim` option defaults
    // to true, and contextScore() compares the prefix's last 8 characters, so a
    // trimmed fingerprint can never match.
    expect(state.storedAnchors).toHaveLength(1);
    const anchor = state.storedAnchors[0];
    expect(anchor.quote).toBe(KNOWN_PHRASE);
    expect(anchor.prefix).toMatch(/ $/);
    expect(anchor.prefix).toContain('contains a');
    expect(anchor.suffix).toMatch(/^ /);
    expect(anchor.suffix).toContain('in this review');
});

test('replying to a thread and resolving it re-render it in place', async ({
    page,
}) => {
    await postComment(page);

    // Asserting both status and content type guards the whole path: CSRF, the
    // {id:comment} entity mapping, and the stream wiring — a wrong content type
    // makes Turbo silently no-op.
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
    ).toContainText(REPLY_BODY, { timeout: coverageScaled(10000) });

    // Same form + Turbo Stream path; the thread is replaced in place and gains
    // the resolved modifier.
    const resolveResponsePromise = page.waitForResponse(
        (r) => r.url().includes('/resolve') && r.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Resolve' }).click();
    const resolveResponse = await resolveResponsePromise;
    expect(resolveResponse.status()).toBe(200);
    expect(resolveResponse.headers()['content-type']).toContain('turbo-stream');

    await expect(page.locator('.lp-comment-thread--resolved')).toBeVisible({
        timeout: coverageScaled(10000),
    });
});

test('requesting changes shows the verdict on the project dashboard', async ({
    page,
    review,
}) => {
    // A verdict is reached on a document that has been commented on, so the
    // thread is part of the state under test, not incidental setup.
    await postComment(page);
    await page.getByRole('button', { name: 'Resolve' }).click();
    await expect(page.locator('.lp-comment-thread--resolved')).toBeVisible({
        timeout: coverageScaled(10000),
    });

    await page.getByRole('button', { name: 'Request changes' }).click();

    // The form POSTs (Turbo Drive) and redirects back to the *same* review URL,
    // so "doc is visible" proves nothing (it never went away). Wait for the
    // success flash, which only renders after the verdict is persisted — otherwise
    // navigating to the dashboard races the POST and reads a stale "In review" badge.
    await expect(page.locator('.lp-flash--success')).toBeVisible({
        timeout: coverageScaled(10000),
    });

    // Scoped to THIS document's row.
    await page.goto(review.dashboardUrl);
    const badge = page.locator(
        `[data-document-id="${review.documentId}"] .lp-badge`,
    );
    await expect(badge).toBeVisible({ timeout: coverageScaled(5000) });
    await expect(badge).toHaveText('Changes requested');

    // Leave and come back: the verdict is stored, not a property of the response
    // that happened to follow the POST.
    await page.goto(review.reviewUrl);
    await page.goto(review.dashboardUrl);
    await expect(badge).toHaveText('Changes requested');
});

test('a resolved comment survives a reload and can then be deleted for good', async ({
    page,
    review,
}) => {
    await postComment(page);

    await page.getByRole('button', { name: 'Resolve' }).click();
    await expect(page.locator('.lp-comment-thread--resolved')).toBeVisible({
        timeout: coverageScaled(10000),
    });

    await page.goto(review.reviewUrl);
    await expect(page.locator(DOC)).toBeVisible();
    const persistedCommentBody = page.locator('.lp-comment-body').first();
    await expect(persistedCommentBody).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await expect(persistedCommentBody).toContainText(COMMENT_BODY);
    await expect(page.locator('.lp-comment-thread')).toHaveCount(1);

    // Delete is a fieldless form guarded by a data-turbo-confirm dialog; accept
    // it, then the Turbo Stream re-renders the thread list without the comment.
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete' }).click();
    await expect(page.locator('.lp-comment-thread')).toHaveCount(0, {
        timeout: coverageScaled(10000),
    });

    await page.goto(review.reviewUrl);
    await expect(page.locator(DOC)).toBeVisible();
    await expect(page.locator('.lp-comment-thread')).toHaveCount(0);
});

/**
 * The composer's hint promises "⌘⏎ to submit", so the shortcut is part of the
 * contract the UI advertises — a plain click-the-button test would not catch
 * its absence. Playwright's "Meta" maps to Cmd on macOS and to the Windows key
 * elsewhere, so press Control+Enter too: the controller accepts either, and
 * this keeps the spec honest on a Linux CI runner.
 */
test('the composer submits on Ctrl/Cmd+Enter', async ({ page, review }) => {
    await openComposer(page);

    const body = page.locator(COMPOSER_BODY);
    await body.fill(COMMENT_BODY);

    // Submit from inside the textarea — the action is bound on the form and
    // relies on keydown bubbling up from the field.
    await body.press('ControlOrMeta+Enter');

    // Same success signal the click path asserts: the Turbo Stream comes back,
    // the composer hides, and the thread appears.
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });
    const commentBody = page.locator('.lp-comment-body').first();
    await expect(commentBody).toBeVisible({ timeout: coverageScaled(10000) });
    await expect(commentBody).toContainText(COMMENT_BODY);

    // Guard against a double submit: the keydown must not also trigger the
    // form's default newline-then-submit behaviour.
    const stateRes = await page.request.get(
        `/dev/review/${review.documentId}/state`,
    );
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as {
        comments: Array<{ quote: string; body: string }>;
    };
    expect(state.comments).toHaveLength(1);
});

/**
 * Hovering a passage rings the card that points at it. The probe runs on every
 * mousemove frame, so it reads the range map #layout() built rather than
 * locating each quote again — this pins the pairing that rewiring must keep.
 */
test('hovering an anchored passage activates its comment card', async ({
    page,
}) => {
    await postComment(page);

    const thread = page
        .locator('[data-comment-anchor-target="thread"]')
        .first();
    await expect(thread).toBeVisible({ timeout: coverageScaled(10000) });
    await expect(thread).not.toHaveClass(/lp-comment-thread--active/);

    // Aim at the middle of the anchored phrase and move the real pointer there,
    // so the controller's own mousemove handler does the hit-testing.
    const box = await page.evaluate((phrase: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        )!;
        const walker = document.createTreeWalker(docEl, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const idx = node.textContent?.indexOf(phrase) ?? -1;
            if (idx !== -1) {
                const range = document.createRange();
                range.setStart(node, idx);
                range.setEnd(node, idx + phrase.length);
                const rect = range.getBoundingClientRect();
                return {
                    x: rect.x + rect.width / 2,
                    y: rect.y + rect.height / 2,
                };
            }
            node = walker.nextNode() as Text | null;
        }
        throw new Error('phrase not found');
    }, KNOWN_PHRASE);

    await page.mouse.move(box.x, box.y);
    await expect(thread).toHaveClass(/lp-comment-thread--active/, {
        timeout: coverageScaled(5000),
    });

    // Moving off it releases the pairing again.
    await page.mouse.move(box.x, box.y - 200);
    await expect(thread).not.toHaveClass(/lp-comment-thread--active/, {
        timeout: coverageScaled(5000),
    });
});
