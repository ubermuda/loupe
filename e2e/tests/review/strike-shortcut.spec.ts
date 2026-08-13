/**
 * The strike keystroke, which is the one part of the review UI that only a human
 * can exercise: it depends on a live DOM selection, on keydown reaching the
 * document, and on auto-repeat semantics. PHPUnit cannot reach any of that, and
 * this project has no JavaScript unit-test harness, so this spec is the only real
 * coverage of the guards in comment_anchor_controller.js.
 *
 * Every test seeds its own user and document through the dev-only endpoints
 * (/dev/register-and-verify, /dev/seed/document), so nothing here touches Mailpit
 * and the tests cannot disturb each other. Strikes are read back from
 * /dev/review/{id}/state, which reports `replacement` — '' is a strike.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

// Guest by default — each test logs in as the user it just created.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

const KNOWN_PHRASE = 'sample phrase for selection';
const DOCUMENT_MARKDOWN = `# E2E Strike Test Document

This paragraph contains a ${KNOWN_PHRASE} in this review.`;

const DOC = '[data-comment-anchor-target="doc"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const SUGGEST_COMPOSER = '[data-comment-anchor-target="suggestComposer"]';

interface ReviewState {
    comments: Array<{
        quote: string;
        body: string;
        replacement: string | null;
    }>;
}

/**
 * Register, log in and seed a document, returning the review URL and the id the
 * state endpoint is keyed by. `tag` keeps each test's user and document distinct.
 */
async function openReview(
    page: Page,
    tag: string,
): Promise<{ reviewUrl: string; documentId: string }> {
    await suppressToolbar(page);
    await suppressWidget(page);

    const email = `e2e+strike+${tag}+${RUN}@example.com`;
    const password = 'E2eStrikeShortcut1!';

    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Striker', email, password },
    });
    expect(registered.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // A fresh user owns no project yet, so LandingController lands on the wizard;
    // seeding the document below creates the project the wizard would have.
    await expect(page).toHaveURL('/welcome');

    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'E2E Strike Test Document',
            markdown: DOCUMENT_MARKDOWN,
        },
    });
    expect(seeded.status()).toBe(201);
    const body = await seeded.json();
    const documentId = body.documentId as string;

    const reviewUrl = `/projects/${body.projectId}/documents/${documentId}/review`;

    await page.goto(reviewUrl);
    await expect(page.locator(DOC)).toBeVisible();

    return { reviewUrl, documentId };
}

/**
 * Select KNOWN_PHRASE by building a DOM Range over it and dispatching mouseup
 * from inside the doc target, which is what the controller listens for. Mirrors
 * the helper in review-loop.spec.ts.
 */
async function selectKnownPhrase(page: Page): Promise<void> {
    await page.evaluate((phrase: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (!docEl) throw new Error('doc target not found');

        const walker = document.createTreeWalker(
            docEl,
            NodeFilter.SHOW_TEXT,
            null,
        );
        let textNode: Text | null = null;
        let nodeOffset = 0;
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const index = node.textContent?.indexOf(phrase) ?? -1;
            if (index !== -1) {
                textNode = node;
                nodeOffset = index;
                break;
            }
            node = walker.nextNode() as Text | null;
        }
        if (!textNode) throw new Error(`Phrase "${phrase}" not found`);

        const range = document.createRange();
        range.setStart(textNode, nodeOffset);
        range.setEnd(textNode, nodeOffset + phrase.length);

        const selection = window.getSelection();
        if (!selection) throw new Error('No selection object');
        selection.removeAllRanges();
        selection.addRange(range);

        docEl.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
    }, KNOWN_PHRASE);

    await expect(page.locator(TOOLBAR)).toBeVisible({ timeout: 5000 });
}

async function strikes(page: Page, documentId: string): Promise<ReviewState> {
    const response = await page.request.get(`/dev/review/${documentId}/state`);
    expect(response.status()).toBe(200);

    return (await response.json()) as ReviewState;
}

/**
 * Counts POSTs the page makes to the strike endpoint.
 *
 * Asserting that a suppressed keypress produced *no* strike cannot be done by
 * checking for absence — a count of zero is also what you see before the request
 * has landed, so the assertion passes for the wrong reason. Counting submissions
 * sidesteps that: a suppressed keypress is one that never issued a request, and
 * requests are counted when issued. Once a later, deliberate strike has rendered,
 * any earlier stray submission is necessarily already in this array.
 */
function countStrikePosts(page: Page): string[] {
    const posts: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().includes('/strikes')) {
            posts.push(request.url());
        }
    });

    return posts;
}

test('a keystroke strikes the selection without ever opening a composer', async ({
    page,
}) => {
    const { documentId } = await openReview(page, 'once');

    await selectKnownPhrase(page);
    await page.keyboard.press('s');

    // The strike renders as struck-through text under a STRIKE status label —
    // the whole premise is that this took one gesture and no typing.
    await expect(page.locator('.lp-comment-status--strike')).toBeVisible({
        timeout: 10000,
    });
    await expect(page.locator('.lp-comment-quote--struck')).toContainText(
        KNOWN_PHRASE,
    );
    await expect(page.locator(COMPOSER)).toBeHidden();
    await expect(page.locator(SUGGEST_COMPOSER)).toBeHidden();

    const state = await strikes(page, documentId);
    expect(state.comments).toHaveLength(1);
    // '' is a strike; null would be an ordinary comment.
    expect(state.comments[0].replacement).toBe('');
    expect(state.comments[0].body).toBe('');
});

test('clicking away disarms the shortcut instead of leaving it on a stale anchor', async ({
    page,
}) => {
    const { documentId } = await openReview(page, 'stale');
    const posts = countStrikePosts(page);

    await selectKnownPhrase(page);

    // Click inside the document to collapse the selection, exactly as a reviewer
    // changing their mind would. The toolbar going away is the signal the
    // controller processed the mouseup.
    await page.locator(DOC).click();
    await expect(page.locator(TOOLBAR)).toBeHidden({ timeout: 5000 });

    // This must do nothing at all.
    await page.keyboard.press('s');

    // Then strike deliberately. This is the synchronisation point as well as the
    // proof that the shortcut does work on this page — so the count below cannot
    // be zero for the boring reason that nothing was ever wired up.
    await selectKnownPhrase(page);
    await page.keyboard.press('s');
    await expect(page.locator('.lp-comment-status--strike')).toBeVisible({
        timeout: 10000,
    });

    expect(posts).toHaveLength(1);
    const state = await strikes(page, documentId);
    expect(state.comments).toHaveLength(1);
    expect(state.comments[0].replacement).toBe('');
});

test('holding the strike key posts one strike, not one per repeat', async ({
    page,
}) => {
    const { documentId } = await openReview(page, 'held');
    const posts = countStrikePosts(page);

    await selectKnownPhrase(page);

    // Playwright sets `repeat: true` on every keydown for a key already held, which
    // is precisely what the OS sends while a key is down.
    await page.keyboard.down('s');
    await page.keyboard.down('s');

    // Let the first strike's response land while the key is still held. This is
    // what makes the test about `event.repeat` specifically: once submit-end has
    // released the in-flight flag, that guard can no longer suppress anything, so
    // the repeats below are held back by nothing else.
    await expect(page.locator('.lp-comment-status--strike')).toBeVisible({
        timeout: 10000,
    });

    await page.keyboard.down('s');
    await page.keyboard.down('s');
    await page.keyboard.up('s');

    expect(posts).toHaveLength(1);
    expect((await strikes(page, documentId)).comments).toHaveLength(1);
});

test('two fast keypresses post one strike, not two', async ({ page }) => {
    const { documentId } = await openReview(page, 'double');
    const posts = countStrikePosts(page);

    await selectKnownPhrase(page);

    // A double-tap is not auto-repeat — each press is a fresh keydown with
    // `repeat: false` — so only the in-flight flag stands between these two and a
    // duplicate strike. This is the likelier of the two mistakes for a real user.
    await page.keyboard.press('s');
    await page.keyboard.press('s');

    await expect(page.locator('.lp-comment-status--strike')).toBeVisible({
        timeout: 10000,
    });

    expect(posts).toHaveLength(1);
    expect((await strikes(page, documentId)).comments).toHaveLength(1);
});

/**
 * The site-review widget composes in a shadow root, and `document.activeElement`
 * stops at the shadow host — so the "caret is in a field" guard saw a DIV, let
 * the shortcut through, and strike() swallowed the keystroke with its
 * preventDefault() before ever checking for a selection. A reviewer writing a
 * comment lost every `s` they typed.
 *
 * The field here is mounted directly rather than driven through the widget: the
 * widget's panel does not open under Playwright on this page, and what the fix
 * changed is the guard, whose contract is that a keydown originating inside a
 * shadow-root field is left alone. That contract is what this pins. The
 * integration itself is covered only by the widget's own harness spec.
 */
test('a keystroke typed inside a shadow-root field is not swallowed', async ({
    page,
}) => {
    const { documentId } = await openReview(page, 'shadow');

    await selectKnownPhrase(page);

    await page.evaluate(() => {
        const host = document.createElement('div');
        host.id = 'shadow-probe-host';
        document.body.appendChild(host);
        const textarea = document.createElement('textarea');
        textarea.id = 'shadow-probe';
        host.attachShadow({ mode: 'open' }).appendChild(textarea);
        textarea.focus();
    });

    // Typed key by key: fill() sets the value without a keydown, so it would
    // pass whether the guard works or not.
    await page.keyboard.type('has s in it');

    // Piercing the shadow root to read the value back the same way the guard has
    // to see into it.
    const typed = await page.evaluate(
        () =>
            (
                document
                    .getElementById('shadow-probe-host')
                    ?.shadowRoot?.querySelector(
                        '#shadow-probe',
                    ) as HTMLTextAreaElement | null
            )?.value ?? null,
    );
    expect(typed).toBe('has s in it');

    // And with a live selection sitting there, nothing was struck either.
    expect((await strikes(page, documentId)).comments).toHaveLength(0);
});
