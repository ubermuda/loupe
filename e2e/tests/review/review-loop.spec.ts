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
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
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

test('New document creates a draft that can be reviewed', async ({
    page,
    review,
}) => {
    await page.goto(review.dashboardUrl);
    await page
        .getByRole('button', { name: 'New document', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: 'New document',
        exact: true,
    });
    await dialog.getByLabel('Title', { exact: true }).fill('A human draft');
    await dialog
        .getByLabel('Markdown', { exact: true })
        .fill('# Draft scope\n\nA new document from the browser.');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toBeHidden();
    await page.getByRole('link', { name: 'Workshop', exact: true }).click();
    await expect(page).not.toHaveURL(review.dashboardUrl);
    await page.getByRole('link', { name: /^Documents \d+$/ }).click();
    await expect(page).toHaveURL(review.dashboardUrl);
    await page
        .getByRole('button', { name: 'New document', exact: true })
        .click();
    await expect(dialog.getByLabel('Title', { exact: true })).toHaveValue(
        'A human draft',
    );
    await dialog
        .getByRole('button', { name: 'Create document', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'A human draft', exact: true }),
    ).toBeVisible({ timeout: 20000 });
    const draftUrl = page.url();
    await expect(page.locator('.lp-review-doc__byline')).toContainText('Draft');
    await expect(page.locator(DOC)).toContainText(
        'A new document from the browser.',
    );
    await expect(page.locator('.lp-verdict-bar')).toHaveCount(0);
    await page.goto(`${review.dashboardUrl}?status=draft`);
    await expect(page.locator('[data-document-id]')).toHaveCount(1);
    await expect(page.locator('[data-document-id]')).toContainText(
        'A human draft',
    );
    await expect(page.locator('[data-document-id]')).toContainText('Draft');
    await page.goto(draftUrl);
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const finishDialog = page.getByRole('dialog', { name: 'Finish review' });
    await finishDialog
        .getByRole('radio', { name: 'Approve', exact: true })
        .check();
    await finishDialog.getByRole('button', { name: 'Submit review' }).click();
    await expect(page.locator('.lp-verdict-bar--approved')).toBeVisible({
        timeout: 20000,
    });
});

test('Revise saves a new version and preserves the previous text', async ({
    page,
    review,
}) => {
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Revise document' });
    await expect(dialog.getByLabel('Markdown', { exact: true })).toHaveValue(
        DOCUMENT_MARKDOWN,
    );
    await dialog
        .getByLabel('Title', { exact: true })
        .fill('Revised review document');
    await dialog
        .getByLabel('Markdown', { exact: true })
        .fill('# Revised content');
    await dialog
        .getByLabel('Revision note', { exact: true })
        .fill('Clarify the document.');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toBeHidden();
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(page).toHaveURL(`${review.reviewUrl}/history`);
    await page.getByRole('link', { name: 'Document', exact: true }).click();
    await expect(page).toHaveURL(review.reviewUrl);
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    await expect(dialog.getByLabel('Title', { exact: true })).toHaveValue(
        'Revised review document',
    );
    await expect(dialog.getByLabel('Markdown', { exact: true })).toHaveValue(
        '# Revised content',
    );
    await expect(
        dialog.getByLabel('Revision note', { exact: true }),
    ).toHaveValue('Clarify the document.');
    await dialog.getByRole('button', { name: 'Save new version' }).click();
    await expect(page.locator('.lp-review-doc__version')).toHaveText('v2', {
        timeout: 20000,
    });
    await expect(page.locator(DOC)).toContainText('Revised content');
    await expect(
        page.getByRole('heading', {
            name: 'Revised review document',
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(`${review.reviewUrl}/versions/1`);
    await expect(page.locator(DOC)).toContainText(KNOWN_PHRASE);
    await expect(
        page.getByRole('button', { name: 'Revise', exact: true }),
    ).toHaveCount(0);
});

test('Revise retains a stale draft and offers the current version', async ({
    page,
    review,
}) => {
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Revise document' });
    await dialog
        .getByLabel('Markdown', { exact: true })
        .fill('# Unsaved draft');
    await dialog
        .getByLabel('Revision note', { exact: true })
        .fill('Keep this note.');
    const revised = await page.request.post(
        `/dev/review/${review.documentId}/revise`,
        {
            form: {
                markdown: '# Concurrent revision',
                description: 'Another revision.',
            },
        },
    );
    expect(revised.status()).toBe(200);
    await dialog.getByRole('button', { name: 'Save new version' }).click();
    await expect(dialog).toContainText('The document has a newer version.', {
        timeout: 20000,
    });
    await expect(dialog.getByLabel('Markdown', { exact: true })).toHaveValue(
        '# Unsaved draft',
    );
    await expect(
        dialog.getByLabel('Revision note', { exact: true }),
    ).toHaveValue('Keep this note.');
    await dialog
        .getByRole('link', { name: 'Go to the current version' })
        .click();
    await expect(page.locator(DOC)).toContainText('Concurrent revision');
    await expect(page.locator('.lp-review-doc__version')).toHaveText('v2');
});

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
    await expect(
        page.locator('.lp-comment-thread__detail').first(),
    ).toBeVisible({
        timeout: coverageScaled(10000),
    });
}

async function expectThreadVisible(page: Page, index = 0): Promise<void> {
    await expect(
        page.locator('.lp-comment-thread__detail').nth(index),
    ).toBeVisible({ timeout: coverageScaled(10000) });
}

for (const width of [1440, 390]) {
    test(`Dismiss clears the selection and disarms Strike at ${width}px`, async ({
        page,
        review,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 1000 });
        const posts: string[] = [];
        page.on('request', (request) => {
            if (
                request.method() === 'POST' &&
                request.url().endsWith('/strikes')
            ) {
                posts.push(request.url());
            }
        });
        await selectKnownPhrase(page, KNOWN_PHRASE);
        const toolbar = page.locator(TOOLBAR);
        const dismiss = toolbar.getByRole('button', {
            name: 'Dismiss selection',
            exact: true,
        });
        const comment = toolbar.getByRole('button', {
            name: 'Comment',
            exact: true,
        });
        await expect(dismiss).toBeVisible();
        await expect(dismiss).toBeInViewport({ ratio: 1 });
        expect((await dismiss.boundingBox())!.height).toBe(
            (await comment.boundingBox())!.height,
        );
        await dismiss.hover();
        const hoverColor = await dismiss.evaluate(async (element) => {
            await Promise.all(
                element.getAnimations().map((animation) => animation.finished),
            );
            return getComputedStyle(element).backgroundColor;
        });
        await comment.hover();
        await expect(comment).toHaveCSS('background-color', hoverColor);
        await page.screenshot({
            path: testInfo.outputPath(`selection-toolbar-${width}.png`),
        });
        await dismiss.focus();
        await dismiss.press('Enter');
        await expect(toolbar).toBeHidden();
        await expect(page.locator(DOC)).toBeFocused();
        expect(
            await page.evaluate(() => window.getSelection()?.toString()),
        ).toBe('');
        await page.keyboard.press('s');

        await selectKnownPhrase(page, KNOWN_PHRASE);
        await toolbar.getByRole('button', { name: /^Strike/ }).click();
        await expect(
            page.locator('.lp-comment-thread[data-anchor-kind="strike"]'),
        ).toBeVisible();
        await expect(page.locator(COMPOSER)).toBeHidden();
        await expect(
            page.locator('[data-comment-anchor-target="suggestComposer"]'),
        ).toBeHidden();
        expect(posts).toHaveLength(1);
        const response = await page.request.get(
            `/dev/review/${review.documentId}/state`,
        );
        expect(response.status()).toBe(200);
        const state = await response.json();
        expect(state.comments).toHaveLength(1);
        expect(state.comments[0]).toMatchObject({
            quote: KNOWN_PHRASE,
            replacement: '',
            body: '',
        });
    });
}

for (const width of [1440, 390]) {
    for (const explanation of ['', 'This wording is more precise.']) {
        test(`Suggest stores replacement and optional explanation at ${width}px: ${explanation || 'no explanation'}`, async ({
            page,
            review,
        }, testInfo) => {
            await page.setViewportSize({ width, height: 1000 });
            await selectKnownPhrase(page, KNOWN_PHRASE);
            await page
                .locator(TOOLBAR)
                .getByRole('button', { name: 'Suggest', exact: true })
                .click();
            const composer = page.locator(
                '[data-comment-anchor-target="suggestComposer"]',
            );
            const replacement = composer.locator(
                '[data-comment-anchor-target="suggestReplacement"]',
            );
            const reason = composer.locator(
                '[data-comment-anchor-target="suggestBody"]',
            );
            await expect(composer).toBeVisible();
            await expect(page.locator('.lp-review-menu__trigger')).toBeHidden();
            await expect(replacement).toBeFocused();
            await expect(replacement).toHaveValue(KNOWN_PHRASE);
            await expect(reason).toHaveValue('');
            await replacement.fill('precise replacement wording');
            await reason.fill(explanation);
            const submit = composer.getByRole('button', {
                name: 'Suggest',
                exact: true,
            });
            await expect(submit).toBeInViewport({ ratio: 1 });
            await composer.evaluate(async (element) => {
                await Promise.all(
                    element
                        .getAnimations()
                        .map((animation) => animation.finished),
                );
            });
            expect(
                await submit.evaluate((element) => {
                    const bounds = element.getBoundingClientRect();
                    return [0.15, 0.5, 0.85].map((fraction) =>
                        element.contains(
                            document.elementFromPoint(
                                bounds.left + bounds.width * fraction,
                                bounds.top + bounds.height / 2,
                            ),
                        ),
                    );
                }),
            ).toEqual([true, true, true]);
            await page.screenshot({
                path: testInfo.outputPath('suggestion-composer.png'),
            });
            await submit.click();
            await expect(composer).toBeHidden();
            const thread = page.locator(
                '[data-comment-anchor-target="thread"]',
            );
            await expect
                .poll(() =>
                    page.locator('.lp-review-menu__trigger').isVisible(),
                )
                .toBe(width < 1024);
            await expect(thread).toHaveCount(1);
            await expect(thread).toContainText('precise replacement wording');
            const response = await page.request.get(
                `/dev/review/${review.documentId}/state`,
            );
            expect(response.status()).toBe(200);
            const state = await response.json();
            expect(state.comments).toHaveLength(1);
            expect(state.comments[0]).toMatchObject({
                quote: KNOWN_PHRASE,
                replacement: 'precise replacement wording',
                body: explanation,
            });
            await page.reload();
            await expect(thread).toContainText('precise replacement wording');
        });
    }
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

    await expectThreadVisible(page);
    const commentBody = page.locator('.lp-comment-body').first();
    await expect(commentBody).toBeVisible({ timeout: coverageScaled(10000) });
    await expect(commentBody).toContainText(COMMENT_BODY);

    // The thread carries the anchored document text. The rail hides the quote,
    // because the passage is highlighted level with the card, so this reads the
    // markup rather than the screen.
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
    await expectThreadVisible(page);
    await page.locator('[data-comment-reply-target=toggle]').first().click();
    await page
        .locator('.lp-comment-reply-form textarea')
        .first()
        .fill(REPLY_BODY);
    await page
        .locator('.lp-comment-reply-form')
        .getByRole('button', { name: 'Reply', exact: true })
        .click();
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

    await expect(page.locator('.lp-comment-thread--resolved')).toHaveCount(1, {
        timeout: coverageScaled(10000),
    });
});

test('a completed review leaves another tabs unsent review recoverable', async ({
    page,
    context,
    review,
}) => {
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Finish review' });
    await dialog
        .getByRole('radio', { name: 'Request changes', exact: true })
        .check();
    await dialog
        .getByRole('textbox', { name: 'Review note' })
        .fill('Keep my unsent review.');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).toBeHidden();
    const other = await context.newPage();
    await other.goto(review.reviewUrl);
    await other
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await other.getByRole('radio', { name: 'Approve', exact: true }).check();
    await other.getByRole('button', { name: 'Submit review' }).click();
    await expect(other.locator('.lp-verdict-bar--approved')).toBeVisible();
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(page).toHaveURL(`${review.reviewUrl}/history`);
    await page.getByRole('link', { name: 'Document', exact: true }).click();
    await expect(page.locator('.lp-verdict-bar--approved')).toBeVisible();
    const recovery = page.locator(
        '[data-form-draft-recovery-key-value="document:review:' +
            review.documentId +
            '"]',
    );
    await expect(recovery).toBeVisible();
    await expect(recovery).toContainText('Keep my unsent review.');
    await expect(recovery).toContainText('Changes requested');
    await expect(
        page.getByRole('button', { name: 'Submit review' }),
    ).toHaveCount(0);
    await recovery.getByRole('button', { name: 'Discard draft' }).click();
    await expect(recovery).toBeHidden();
    await expect(page.locator('#review-document-title')).toBeFocused();
    await expect(page.locator('.lp-verdict-bar--approved')).toBeVisible();
    await other.close();
});

test('a stale review page cannot approve a newer version', async ({
    page,
    review,
}) => {
    const revised = await page.request.post(
        `/dev/review/${review.documentId}/revise`,
        {
            form: {
                markdown:
                    '# Revised document\n\nThis version needs its own review.',
            },
        },
    );
    expect(revised.status()).toBe(200);
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await page.getByRole('radio', { name: 'Approve', exact: true }).check();
    await page
        .getByRole('textbox', { name: 'Review note' })
        .fill('Keep this draft.');
    await page.getByRole('button', { name: 'Submit review' }).click();
    await expect(
        page
            .getByRole('dialog', { name: 'Finish review' })
            .locator('.lp-field-errors')
            .filter({ hasText: 'The document has a newer version.' }),
    ).toContainText('The document has a newer version.');
    await expect(
        page.getByRole('textbox', { name: 'Review note' }),
    ).toHaveValue('Keep this draft.');
    await page.getByRole('link', { name: 'Go to the current version' }).click();
    await expect(page.locator('.lp-review-doc__version')).toHaveText('v2');
    await expect(page.locator('.lp-verdict-bar')).toHaveCount(0);
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const restored = page.getByRole('dialog', { name: 'Finish review' });
    await expect(
        restored.getByRole('textbox', { name: 'Review note' }),
    ).toHaveValue('Keep this draft.');
    await expect(
        restored.locator('[data-form-draft-target="guard"]').first(),
    ).toHaveValue('1');
    await expect(
        restored.locator('[data-form-draft-target="stale"]'),
    ).toBeVisible();
    await restored.getByRole('button', { name: 'Discard draft' }).click();
    await expect(
        restored.getByRole('textbox', { name: 'Review note' }),
    ).toHaveValue('');
    await expect(
        restored.locator('[data-form-draft-target="guard"]').first(),
    ).toHaveValue('2');
    await restored.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.goto(review.dashboardUrl);
    await expect(
        page.locator(`[data-document-id="${review.documentId}"] .lp-badge`),
    ).toHaveText('In review');
});

test('a stale withdrawal preserves the verdict from another tab', async ({
    page,
    context,
    review,
}) => {
    // Two tabs, three verdict changes and two reloads, each a server round trip.
    test.slow();
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await page.getByRole('radio', { name: 'Approve', exact: true }).check();
    await page.getByRole('button', { name: 'Submit review' }).click();
    await expect(page.locator('.lp-verdict-bar--approved')).toBeVisible();

    const current = await context.newPage();
    await suppressToolbar(current);
    await suppressWidget(current);
    await current.goto(review.reviewUrl);
    await current
        .locator('.lp-verdict-bar__undo')
        .getByRole('button', { name: 'Undo', exact: true })
        .click();
    await expect(current.locator('.lp-flash--success')).toContainText(
        'Your verdict has been withdrawn.',
    );
    await current
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await current
        .getByRole('radio', { name: 'Request changes', exact: true })
        .check();
    await current
        .getByRole('textbox', { name: 'Review note' })
        .fill('Clarify the retry policy.');
    await current.getByRole('button', { name: 'Submit review' }).click();
    await expect(
        current.locator('.lp-verdict-bar--changes-requested'),
    ).toContainText('Clarify the retry policy.');

    await page
        .locator('.lp-verdict-bar__undo')
        .getByRole('button', { name: 'Undo', exact: true })
        .click();
    await expect(page.locator('[data-review-withdrawal-errors]')).toContainText(
        'The review changed after this page loaded.',
    );
    await expect(
        page.locator('.lp-verdict-bar--changes-requested'),
    ).toContainText('Clarify the retry policy.');
    await page.reload();
    await expect(
        page.locator('.lp-verdict-bar--changes-requested'),
    ).toContainText('Clarify the retry policy.');
    await page
        .locator('.lp-verdict-bar__undo')
        .getByRole('button', { name: 'Undo', exact: true })
        .click();
    await expect(page.locator('.lp-flash--success')).toContainText(
        'Your verdict has been withdrawn.',
    );
    await expect(page.locator('.lp-verdict-bar')).toHaveCount(0);
    await page.reload();
    await expect(page.locator('.lp-review-doc__byline')).toContainText(
        'In review',
    );
    await expect(page.locator('.lp-verdict-bar')).toHaveCount(0);
});

test('requesting changes shows the verdict on the project dashboard', async ({
    page,
    review,
}) => {
    // A verdict is reached on a document that has been commented on, so the
    // thread is part of the state under test, not incidental setup.
    await postComment(page);
    await expectThreadVisible(page);
    await page.getByRole('button', { name: 'Resolve' }).click();
    await expect(page.locator('.lp-comment-thread--resolved')).toHaveCount(1, {
        timeout: coverageScaled(10000),
    });

    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await page.getByRole('radio', { name: 'Request changes' }).check();
    await page.getByRole('button', { name: 'Submit review' }).click();
    await expect(
        page.getByRole('dialog', { name: 'Finish review' }),
    ).toContainText('Explain the changes you request in a review note.');
    await page
        .getByRole('textbox', { name: 'Review note' })
        .fill('Explain the retry behaviour.');
    await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(
        page.getByRole('dialog', { name: 'Finish review' }),
    ).toBeHidden();
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await expect(
        page.getByRole('textbox', { name: 'Review note' }),
    ).toHaveValue('Explain the retry behaviour.');
    await page.getByRole('button', { name: 'Submit review' }).click();

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
    await expect(page.locator('.lp-review-verdict-note')).toHaveText(
        'Explain the retry behaviour.',
    );
    await page.goto(review.dashboardUrl);
    await expect(badge).toHaveText('Changes requested');
});

for (const width of [1440, 390]) {
    test(`a thread can be deleted and undone at ${width}px`, async ({
        page,
        review,
    }) => {
        await page.setViewportSize({ width, height: 900 });
        await postComment(page);
        await expectThreadVisible(page);

        await page.goto(review.reviewUrl);
        await expect(page.locator(DOC)).toBeVisible();
        await expectThreadVisible(page);
        const persistedCommentBody = page.locator('.lp-comment-body').first();
        await expect(persistedCommentBody).toBeVisible({
            timeout: coverageScaled(5000),
        });
        await expect(persistedCommentBody).toContainText(COMMENT_BODY);
        await expect(page.locator('.lp-comment-thread')).toHaveCount(1);
        const threadId = await page
            .locator('.lp-comment-thread')
            .getAttribute('id');

        // Delete is a fieldless form guarded by a data-turbo-confirm dialog; accept
        // it, then the Turbo Stream re-renders the thread list without the comment.
        page.on('dialog', (dialog) => dialog.accept());
        await page.getByRole('button', { name: 'Delete' }).click();
        await expect(page.locator('.lp-comment-thread')).toHaveCount(0, {
            timeout: coverageScaled(10000),
        });
        await expect(page.locator('#comment-recovery')).toContainText(
            'Thread deleted.',
        );
        await page.getByRole('button', { name: 'Undo', exact: true }).click();
        await expect(page.locator('.lp-comment-thread')).toHaveAttribute(
            'id',
            threadId!,
        );
        await expect(page.locator('.lp-comment-body').first()).toContainText(
            COMMENT_BODY,
        );

        // The undo notice is the only recovery surface, so a second delete that
        // the reader walks away from leaves the thread gone for good.
        await page.getByRole('button', { name: 'Delete', exact: true }).click();
        await expect(page.locator('.lp-comment-thread')).toHaveCount(0);
        await page.goto(review.reviewUrl);
        await expect(page.locator(DOC)).toBeVisible();
        await expect(page.locator('.lp-comment-thread')).toHaveCount(0);
        await page.getByRole('tab', { name: 'Details', exact: true }).click();
        await expect(
            page.getByRole('link', { name: 'Deleted threads' }),
        ).toHaveCount(0);
    });
}

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
    await expectThreadVisible(page);
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
