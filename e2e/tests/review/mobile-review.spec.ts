/**
 * Browser coverage for the review screen at a phone width.
 *
 * Below the lg breakpoint the review block is fluid, the comment margin loses
 * its absolute position, and comment_anchor_controller moves each card into the
 * prose after the block it points at. Above lg the margin layout is unchanged.
 *
 * Geometry is measured against `.lp-main`, the app's scroll container, and not
 * against the window. `.lp-main` carries overflow-x, so a column wider than the
 * screen shows up as horizontal overflow there and never on the document. The
 * app shell around it still overflows a 375px window, and a sibling branch owns
 * the sidebar and the topbar that do it.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eMobileReview1!';

const KNOWN_PHRASE = 'sample phrase for selection';
const DOCUMENT_MARKDOWN = `# E2E Mobile Review Document

This first paragraph contains a ${KNOWN_PHRASE} in this review.

This second paragraph carries no anchor at all.`;

const COMMENT_BODY = 'This comment must flow into the prose on a phone.';

const DOC = '[data-comment-anchor-target="doc"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';
const THREAD = '.lp-comment-thread';
const ACTIVE_THREAD = '.lp-comment-thread.lp-comment-thread--active';
const SLOT = '.lp-review-inline-threads';

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

async function devRegisterAndVerify(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Mobile Reviewer', email, password },
    });
    expect(response.status()).toBe(200);
}

async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', {
        timeout: coverageScaled(15000),
    });
}

async function seedDocument(
    page: Page,
): Promise<{ documentId: string; projectId: string }> {
    const response = await page.request.post('/dev/seed/document', {
        form: {
            title: 'E2E Mobile Review Document',
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
 * Selects the known phrase with the Selection API and dispatches no event.
 *
 * A touch selection raises no mouseup, so this is the path a finger takes: the
 * browser fires selectionchange and the controller has to act on that alone.
 */
async function selectByTouch(page: Page, phrase: string): Promise<void> {
    await page.evaluate((phraseToSelect: string) => {
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
            const index = node.textContent?.indexOf(phraseToSelect) ?? -1;
            if (index !== -1) {
                textNode = node;
                nodeOffset = index;
                break;
            }
            node = walker.nextNode() as Text | null;
        }
        if (!textNode) {
            throw new Error(`Phrase "${phraseToSelect}" not found in the doc`);
        }

        const range = document.createRange();
        range.setStart(textNode, nodeOffset);
        range.setEnd(textNode, nodeOffset + phraseToSelect.length);

        const selection = window.getSelection();
        if (!selection) throw new Error('No selection object');
        selection.removeAllRanges();
        selection.addRange(range);
    }, phrase);
}

/** Posts one comment anchored to the known phrase and waits for its card. */
async function postComment(page: Page): Promise<void> {
    await selectByTouch(page, KNOWN_PHRASE);
    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.locator(COMPOSER_BODY).fill(COMMENT_BODY);
    await page.getByRole('button', { name: 'Post' }).click();
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });
    await expect(page.locator(THREAD)).toHaveCount(1, {
        timeout: coverageScaled(10000),
    });
}

/** Viewport midpoint of the anchored phrase, which is painted with no element. */
async function phraseMidpoint(
    page: Page,
    phrase: string,
): Promise<{ x: number; y: number }> {
    return page.evaluate((phraseToFind: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        )!;
        const walker = document.createTreeWalker(
            docEl,
            NodeFilter.SHOW_TEXT,
            null,
        );
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const index = node.textContent?.indexOf(phraseToFind) ?? -1;
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + phraseToFind.length);
                const rect = range.getClientRects()[0];
                return {
                    x: rect.left + rect.width / 2,
                    y: rect.top + rect.height / 2,
                };
            }
            node = walker.nextNode() as Text | null;
        }
        throw new Error('phrase not found');
    }, phrase);
}

/** Geometry of the review screen, read after the layout frame has run. */
async function readLayout(page: Page) {
    await page.evaluate(
        () => new Promise((resolve) => requestAnimationFrame(resolve)),
    );

    return page.evaluate(() => {
        const main = document.querySelector('.lp-main')!;
        const doc = document.querySelector('.lp-review-doc')!;
        const prose = document.querySelector('.lp-review-doc__prose')!;
        const margin = document.querySelector('.lp-review-margin')!;
        const mainRect = main.getBoundingClientRect();
        const docRect = doc.getBoundingClientRect();
        const thread = document.querySelector('.lp-comment-thread');
        const threadRect = thread?.getBoundingClientRect() ?? null;
        const commentBody = document.querySelector('.lp-comment-body');
        const commentQuote = document.querySelector('.lp-comment-quote');
        const paragraph = document.querySelector(
            '[data-comment-anchor-target="doc"] p',
        );

        return {
            mainScrollWidth: main.scrollWidth,
            mainClientWidth: main.clientWidth,
            mainLeft: mainRect.left,
            mainRight: mainRect.right,
            docLeft: docRect.left,
            docRight: docRect.right,
            docWidth: docRect.width,
            proseScrollWidth: prose.scrollWidth,
            proseClientWidth: prose.clientWidth,
            marginPosition: getComputedStyle(margin).position,
            threadParentClass: thread?.parentElement?.className ?? null,
            threadPosition:
                thread === null ? null : getComputedStyle(thread).position,
            threadLeft: threadRect?.left ?? null,
            threadRight: threadRect?.right ?? null,
            threadTop: threadRect?.top ?? null,
            paragraphBottom: paragraph?.getBoundingClientRect().bottom ?? null,
            slotFollowsParagraph:
                thread?.parentElement?.previousElementSibling === paragraph,
            // The slot sits inside the rendered prose, so a card in it must not
            // pick the document's reading type up from around it.
            cardType:
                commentBody === null
                    ? null
                    : [
                          getComputedStyle(commentBody).fontSize,
                          getComputedStyle(commentBody).lineHeight,
                      ].join(' '),
            quoteType:
                commentQuote === null
                    ? null
                    : [
                          getComputedStyle(commentQuote).fontSize,
                          getComputedStyle(commentQuote).fontStyle,
                          getComputedStyle(commentQuote).borderLeftWidth,
                          getComputedStyle(commentQuote).paddingLeft,
                      ].join(' '),
        };
    });
}

/**
 * Widens the window until the reading area itself is 375px.
 *
 * The sidebar takes its own width at every viewport today, so a 375px window
 * leaves the review screen about 129px. That is the app shell's geometry rather
 * than this screen's, and a sibling branch collapses the sidebar at the same
 * breakpoint. Measuring the shell and adding it back gives this screen the width
 * a phone hands it once that branch lands.
 */
async function givePhoneWidthReadingArea(page: Page): Promise<void> {
    const shell = await page.evaluate(
        () =>
            window.innerWidth - document.querySelector('.lp-main')!.clientWidth,
    );
    await page.setViewportSize({
        width: PHONE.width + shell,
        height: PHONE.height,
    });
}

interface SeededReview {
    reviewUrl: string;
}

const test = base.extend<{ review: SeededReview }>({
    review: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+mobilereview+${tag}+${RUN}@example.com`;

            await devRegisterAndVerify(page, email, PASSWORD);
            await login(page, email, PASSWORD);

            const { documentId, projectId } = await seedDocument(page);
            const reviewUrl = `/projects/${projectId}/documents/${documentId}/review`;

            await page.goto(reviewUrl);
            await expect(page.locator(DOC)).toBeVisible();

            await use({ reviewUrl });
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: PHONE,
    hasTouch: true,
});

test('the reading column fills the screen instead of being clipped', async ({
    page,
}) => {
    const layout = await readLayout(page);

    expect(layout.marginPosition).toBe('static');
    // The column was a fixed 640px inside a fixed 972px block, so it ran far
    // past the right edge of the scroll container and had to be scrolled to.
    expect(layout.docLeft).toBeGreaterThanOrEqual(layout.mainLeft - 1);
    expect(layout.docRight).toBeLessThanOrEqual(layout.mainRight + 1);
    expect(layout.docWidth).toBeGreaterThan(layout.mainClientWidth - 45);
    expect(layout.proseScrollWidth).toBeLessThanOrEqual(
        layout.proseClientWidth + 1,
    );
});

test('a 375px reading area scrolls in one direction only', async ({ page }) => {
    await givePhoneWidthReadingArea(page);

    const layout = await readLayout(page);

    expect(layout.mainClientWidth).toBe(PHONE.width);
    expect(layout.mainScrollWidth).toBeLessThanOrEqual(layout.mainClientWidth);
});

test('a touch selection raises the comment toolbar', async ({ page }) => {
    await expect(page.locator(TOOLBAR)).toBeHidden();

    // A long press that becomes a selection, and a touch that becomes a scroll,
    // both end in pointercancel. The controller must not read that as a pointer
    // still held down, or the toolbar never appears again on that page.
    await page.evaluate(() => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        )!;
        for (const type of ['pointerdown', 'pointercancel']) {
            docEl.dispatchEvent(
                new PointerEvent(type, { bubbles: true, pointerType: 'touch' }),
            );
        }
    });
    await selectByTouch(page, KNOWN_PHRASE);

    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
});

test('a tap on a highlighted passage rings its card', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await postComment(page);
    await givePhoneWidthReadingArea(page);
    // Posting leaves the passage selected, and a click that ends a selection is
    // not a tap. Reloading is also how a reviewer reaches the page on a phone.
    await page.reload();
    await expect(page.locator(SLOT)).toHaveCount(1, {
        timeout: coverageScaled(10000),
    });
    await expect(page.locator(ACTIVE_THREAD)).toHaveCount(0);

    const point = await phraseMidpoint(page, KNOWN_PHRASE);
    await page.touchscreen.tap(point.x, point.y);

    await expect(page.locator(ACTIVE_THREAD)).toHaveCount(1, {
        timeout: coverageScaled(5000),
    });
});

test('a comment card flows into the prose below lg and returns above it', async ({
    page,
}) => {
    await page.setViewportSize(DESKTOP);
    await postComment(page);

    const desktop = await readLayout(page);
    expect(desktop.marginPosition).toBe('absolute');
    expect(desktop.threadPosition).toBe('absolute');
    expect(desktop.threadParentClass).toContain('lp-review-margin');
    // The card sits in the gutter, clear of the reading column.
    expect(desktop.threadLeft!).toBeGreaterThan(desktop.docRight - 1);

    await givePhoneWidthReadingArea(page);

    const phone = await readLayout(page);
    expect(phone.marginPosition).toBe('static');
    expect(phone.threadPosition).toBe('static');
    expect(phone.threadParentClass).toContain('lp-review-inline-threads');
    expect(phone.slotFollowsParagraph).toBe(true);
    expect(phone.threadTop!).toBeGreaterThanOrEqual(phone.paragraphBottom!);
    expect(phone.threadRight!).toBeLessThanOrEqual(phone.mainRight + 1);
    expect(phone.mainScrollWidth).toBeLessThanOrEqual(phone.mainClientWidth);
    expect(phone.cardType).toBe(desktop.cardType);
    expect(phone.quoteType).toBe(desktop.quoteType);

    await page.setViewportSize(DESKTOP);

    const back = await readLayout(page);
    expect(back.threadParentClass).toContain('lp-review-margin');
    expect(back.threadPosition).toBe('absolute');
    // Nothing is left behind in the prose when the margin comes back.
    await expect(page.locator(SLOT)).toHaveCount(0);
    await expect(page.locator(THREAD)).toHaveCount(1);
});
