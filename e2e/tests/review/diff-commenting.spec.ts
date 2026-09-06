/**
 * Commenting from a diff.
 *
 * The pane shows the text of two versions at once, so the anchor a selection
 * produces cannot be sliced out of the pane the way the document pane's is.
 * RenderedDiffBuilder marks the runs the newer version also holds with their
 * offset into that version's plain text, and the controller anchors to those
 * alone. None of that is reachable from PHPUnit: it needs a live DOM selection.
 *
 * Every test seeds its own user and document through the dev-only endpoints, so
 * nothing here touches Mailpit and the tests cannot disturb each other.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

// Guest by default — each test logs in as the user it just created.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

const VERSION_ONE = `# Diff Comment Doc

The rollout takes one step in this plan.`;

const VERSION_TWO = `# Diff Comment Doc

The rollout takes three careful steps in this plan.`;

const VERSION_THREE = `# Diff Comment Doc

The rollout takes three careful steps in this revised plan.`;

const INSERTED = 'three careful steps';
const DELETED = 'one step';

// A revision that only removes a word, so a current-version quote either side of
// the removal reads as one passage while the diff draws the removed word between.
const SPANNING_ONE = `# Diff Comment Doc

Alpha OLD Beta.`;

const SPANNING_TWO = `# Diff Comment Doc

Alpha Beta.`;

const SPANNING_QUOTE = 'Alpha Beta';

const DOC = '[data-comment-anchor-target="doc"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';
const ACTION_ERROR = '[data-comment-anchor-target="actionError"]';

interface ReviewState {
    storedAnchors: Array<{ quote: string; prefix: string; suffix: string }>;
}

/**
 * Register, log in and seed a document with `revisions` applied after its first
 * version, returning the ids the diff URL and the state endpoint are keyed by.
 */
async function seedDocument(
    page: Page,
    tag: string,
    revisions: string[],
    first: string = VERSION_ONE,
): Promise<{ projectId: string; documentId: string }> {
    await suppressToolbar(page);
    await suppressWidget(page);

    const email = `e2e+diffcomment+${tag}+${RUN}@example.com`;
    const password = 'E2eDiffComment1!';

    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Diff Reviewer', email, password },
    });
    expect(registered.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome');

    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Diff Comment Doc',
            markdown: first,
            revisions: JSON.stringify(revisions),
        },
    });
    expect(seeded.status()).toBe(201);
    const body = await seeded.json();

    return {
        projectId: body.projectId as string,
        documentId: body.documentId as string,
    };
}

/**
 * Select a phrase inside the diff pane by building a DOM Range over it and
 * dispatching mouseup, which is what the controller listens for. `endPhrase`
 * extends the range to the end of a second phrase, so a selection can be made
 * to cross a deleted run.
 */
async function selectPhrase(
    page: Page,
    phrase: string,
    endPhrase?: string,
): Promise<void> {
    await page.evaluate(
        ({ from, to }: { from: string; to?: string }) => {
            const docEl = document.querySelector(
                '[data-comment-anchor-target="doc"]',
            );
            if (!docEl) throw new Error('doc target not found');

            const find = (needle: string): { node: Text; index: number } => {
                const walker = document.createTreeWalker(
                    docEl,
                    NodeFilter.SHOW_TEXT,
                    null,
                );
                let node = walker.nextNode() as Text | null;
                while (node !== null) {
                    const index = node.textContent?.indexOf(needle) ?? -1;
                    if (index !== -1) return { node, index };
                    node = walker.nextNode() as Text | null;
                }
                throw new Error(`Phrase "${needle}" not found`);
            };

            const start = find(from);
            const end = to === undefined ? null : find(to);

            const range = document.createRange();
            range.setStart(start.node, start.index);
            if (end === null) {
                range.setEnd(start.node, start.index + from.length);
            } else {
                range.setEnd(end.node, end.index + to.length);
            }

            const selection = window.getSelection();
            if (!selection) throw new Error('No selection object');
            selection.removeAllRanges();
            selection.addRange(range);

            docEl.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
        },
        { from: phrase, to: endPhrase },
    );
}

/**
 * The first anchor the controller painted, described by where it landed: the
 * text, whether its run carries a newer-version offset, and whether it sits in
 * removed text.
 */
async function paintedAnchor(page: Page): Promise<{
    text: string;
    stamped: boolean;
    deleted: boolean;
} | null> {
    return page.evaluate(() => {
        const registry = (
            CSS as unknown as { highlights?: Map<string, Set<Range>> }
        ).highlights;
        const highlight = registry?.get('lp-anchor-pending');
        for (const range of highlight ?? []) {
            const parent = range.startContainer.parentElement;
            const covered = range.cloneContents();

            return {
                text: range.toString(),
                stamped: parent?.hasAttribute('data-diff-offset') ?? false,
                deleted:
                    Boolean(parent?.closest('.lp-diff__mark--deleted')) ||
                    covered.querySelector('.lp-diff__mark--deleted') !== null,
            };
        }

        return null;
    });
}

async function reviewState(
    page: Page,
    documentId: string,
): Promise<ReviewState> {
    const response = await page.request.get(`/dev/review/${documentId}/state`);
    expect(response.status()).toBe(200);

    return (await response.json()) as ReviewState;
}

test('a comment made on an inserted run lands on the current version', async ({
    page,
}) => {
    const { projectId, documentId } = await seedDocument(page, 'inserted', [
        VERSION_TWO,
    ]);

    await page.goto(
        `/projects/${projectId}/documents/${documentId}/review/diff/1/2`,
    );
    await expect(page.locator(DOC)).toBeVisible();
    // The run this comments on is one the revision added.
    await expect(
        page
            .locator(`.lp-diff__mark--inserted:has-text("${INSERTED}")`)
            .first(),
    ).toBeVisible();

    await selectPhrase(page, INSERTED);
    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });

    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeVisible();
    await page.locator(COMPOSER_BODY).fill('Three steps reads better.');
    await page.getByRole('button', { name: 'Post' }).click();
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });

    // The thread list is on the diff page too, so the stream that replaces it
    // has somewhere to land.
    await expect(page.locator('#comment-threads')).toContainText(
        'Three steps reads better.',
        { timeout: coverageScaled(10000) },
    );

    // storedAnchors reads the LATEST version's comments, so the quote appearing
    // here is the whole claim: an ordinary anchor on the current version.
    const state = await reviewState(page, documentId);
    expect(state.storedAnchors).toHaveLength(1);
    expect(state.storedAnchors[0].quote).toBe(INSERTED);

    // The thread is painted back onto a run the newer version holds, rather than
    // onto whichever occurrence the pane's combined text happened to offer.
    await expect
        .poll(async () => paintedAnchor(page), {
            timeout: coverageScaled(10000),
        })
        .toEqual({ text: INSERTED, stamped: true, deleted: false });
});

test('a selection that touches deleted text is refused', async ({ page }) => {
    const { projectId, documentId } = await seedDocument(page, 'deleted', [
        VERSION_TWO,
    ]);

    await page.goto(
        `/projects/${projectId}/documents/${documentId}/review/diff/1/2`,
    );
    await expect(page.locator(DOC)).toBeVisible();

    await expect(
        page.locator(`.lp-diff__mark--deleted:has-text("${DELETED}")`).first(),
    ).toBeVisible();

    // From the unchanged word before the change, through the removed run, to the
    // inserted one after it.
    await selectPhrase(page, 'takes', INSERTED);

    await expect(page.locator(ACTION_ERROR)).toContainText(
        'text this revision removed',
        { timeout: coverageScaled(5000) },
    );
    await expect(page.locator(TOOLBAR)).toBeHidden();

    // A selection that avoids the removed run still works, so the refusal above
    // is about the deleted text and not about the pane refusing everything.
    await selectPhrase(page, INSERTED);
    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await expect(page.locator(ACTION_ERROR)).toHaveText('');

    expect((await reviewState(page, documentId)).storedAnchors).toHaveLength(0);
});

test('a diff whose newer side is not the current version offers no commenting', async ({
    page,
}) => {
    const { projectId, documentId } = await seedDocument(page, 'stale', [
        VERSION_TWO,
        VERSION_THREE,
    ]);

    // v2 is no longer the version a comment would land on, so this pair is read
    // only however much of it the newer side still holds.
    await page.goto(
        `/projects/${projectId}/documents/${documentId}/review/diff/1/2`,
    );
    await expect(page.locator('.lp-diff-doc')).toBeVisible();
    await expect(page.locator(DOC)).toHaveCount(0);
    await expect(page.locator(TOOLBAR)).toHaveCount(0);
    await expect(page.locator('#comment-threads')).toHaveCount(0);

    // The pair that does end at the current version still accepts one.
    await page.goto(
        `/projects/${projectId}/documents/${documentId}/review/diff/2/3`,
    );
    await expect(page.locator(DOC)).toBeVisible();
    await expect(page.locator(TOOLBAR)).toHaveCount(1);
});

/**
 * A comment made on the current version can quote a passage the diff draws a
 * removed word inside of. The pane offers no unbroken stretch for it, so the
 * anchor goes unpainted rather than tinting the removed word as part of it.
 */
test('an anchor that spans a removal is never painted over the removed text', async ({
    page,
}) => {
    const { projectId, documentId } = await seedDocument(
        page,
        'spanning',
        [SPANNING_TWO],
        SPANNING_ONE,
    );

    // The comment is made on the document itself, where the passage reads whole.
    await page.goto(`/projects/${projectId}/documents/${documentId}/review`);
    await expect(page.locator(DOC)).toBeVisible();
    await selectPhrase(page, SPANNING_QUOTE);
    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await page.locator(COMPOSER_BODY).fill('Reads well now.');
    await page.getByRole('button', { name: 'Post' }).click();
    await expect(page.locator('#comment-threads')).toContainText(
        'Reads well now.',
        { timeout: coverageScaled(10000) },
    );

    // Painted on the document page, so the diff page's answer below is about the
    // diff and not about the anchor being unusable everywhere.
    await expect
        .poll(async () => paintedAnchor(page), {
            timeout: coverageScaled(10000),
        })
        .toEqual({ text: SPANNING_QUOTE, stamped: false, deleted: false });

    await page.goto(
        `/projects/${projectId}/documents/${documentId}/review/diff/1/2`,
    );
    await expect(page.locator(DOC)).toBeVisible();
    await expect(page.locator('#comment-threads')).toContainText(
        'Reads well now.',
    );
    await expect(page.locator('.lp-diff__mark--deleted').first()).toBeVisible();

    // The card still renders; only its tint is withheld.
    await expect
        .poll(async () => paintedAnchor(page), {
            timeout: coverageScaled(10000),
        })
        .toBeNull();
});
