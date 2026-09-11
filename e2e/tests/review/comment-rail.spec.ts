/**
 * Browser coverage for the comment rail: the margin column above the lg
 * breakpoint, where every thread is one marker row and one thread at a time
 * opens in place.
 *
 * Three passages in one short paragraph, so the three markers compete for the
 * same vertical space — which is the case the rail exists for.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

const RUN = Date.now();
const PASSWORD = 'E2eCommentRail1!';

const FIRST = 'first anchored phrase';
const SECOND = 'second anchored phrase';
const THIRD = 'third anchored phrase';

const DOCUMENT_MARKDOWN = `# E2E Comment Rail Document

This paragraph holds a ${FIRST} and then a ${SECOND} and finally a ${THIRD}.`;

const DOC = '[data-comment-anchor-target="doc"]';
const TOOLBAR = '[data-comment-anchor-target="toolbar"]';
const COMPOSER = '[data-comment-anchor-target="composer"]';
const COMPOSER_BODY = '[data-comment-anchor-target="composerBody"]';
const MARKER = '.lp-comment-marker';
const THREAD = '.lp-comment-thread';
const EXPANDED = '.lp-comment-thread.lp-comment-thread--expanded';

async function devRegisterAndVerify(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Rail Reviewer', email, password },
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
    await expect(page).toHaveURL('/welcome');
}

const test = base.extend<{ reviewUrl: string }>({
    reviewUrl: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+rail+${tag}+${RUN}@example.com`;
            await devRegisterAndVerify(page, email, PASSWORD);
            await login(page, email, PASSWORD);

            const response = await page.request.post('/dev/seed/document', {
                form: {
                    title: 'E2E Comment Rail Document',
                    markdown: DOCUMENT_MARKDOWN,
                },
            });
            expect(response.status()).toBe(201);
            const body = await response.json();
            const url = `/projects/${body.projectId as string}/documents/${body.documentId as string}/review`;

            await page.goto(url);
            await expect(page.locator(DOC)).toBeVisible();

            await use(url);
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: { width: 1440, height: 900 },
});

/** Select a phrase in the prose the way a drag would, then post a comment on it. */
async function commentOn(
    page: Page,
    phrase: string,
    body: string,
): Promise<void> {
    await page.evaluate((wanted: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (docEl === null) {
            throw new Error('doc target not found');
        }
        const walker = document.createTreeWalker(docEl, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode() as Text | null;
        while (node !== null) {
            const index = node.textContent?.indexOf(wanted) ?? -1;
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + wanted.length);
                const selection = window.getSelection();
                if (selection === null) {
                    throw new Error('no selection object');
                }
                selection.removeAllRanges();
                selection.addRange(range);
                docEl.dispatchEvent(
                    new MouseEvent('mouseup', { bubbles: true }),
                );

                return;
            }
            node = walker.nextNode() as Text | null;
        }
        throw new Error(`phrase "${wanted}" not found in the prose`);
    }, phrase);

    await expect(page.locator(TOOLBAR)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeVisible({
        timeout: coverageScaled(5000),
    });
    await page.locator(COMPOSER_BODY).fill(body);
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });
}

/** Every marker's `top`, in DOM order, once the layout has settled. */
async function markerTops(page: Page): Promise<number[]> {
    return page.evaluate(() =>
        [...document.querySelectorAll('.lp-comment-thread')]
            .filter((thread) => (thread as HTMLElement).offsetParent !== null)
            .map((thread) =>
                Math.round((thread as HTMLElement).getBoundingClientRect().top),
            ),
    );
}

async function seedThreeThreads(page: Page): Promise<void> {
    await commentOn(page, FIRST, 'The first passage needs a number.');
    await commentOn(page, SECOND, 'The second one contradicts the first.');
    await commentOn(page, THIRD, 'Name the owner of the third.');
    await expect(page.locator(THREAD)).toHaveCount(3, {
        timeout: coverageScaled(10000),
    });
}

test('threads anchored close together render as markers, not cards', async ({
    page,
}) => {
    await seedThreeThreads(page);

    const markers = page.locator(MARKER);
    await expect(markers).toHaveCount(3);
    for (let index = 0; index < 3; index += 1) {
        await expect(markers.nth(index)).toBeVisible();
        await expect(markers.nth(index)).toHaveAttribute(
            'aria-expanded',
            'false',
        );
    }

    // Nothing is open, so no body, no quote and no reply box is on screen.
    await expect(page.locator('.lp-comment-body:visible')).toHaveCount(0);
    await expect(page.locator(`${THREAD} textarea:visible`)).toHaveCount(0);

    // Each marker is its own row rather than a card stacked on its neighbour:
    // one 32px marker row inside the padding every card keeps in both states.
    const heights = await page.evaluate(() =>
        [...document.querySelectorAll('.lp-comment-thread')].map(
            (thread) => (thread as HTMLElement).offsetHeight,
        ),
    );
    expect(heights).toEqual([60, 60, 60]);

    const tops = await markerTops(page);
    expect(tops[1]).toBeGreaterThan(tops[0]);
    expect(tops[2]).toBeGreaterThan(tops[1]);
});

test('one marker expands on click and a second collapses the first', async ({
    page,
}) => {
    await seedThreeThreads(page);

    const markers = page.locator(MARKER);
    await markers.nth(0).click();
    await expect(page.locator(EXPANDED)).toHaveCount(1);
    await expect(markers.nth(0)).toHaveAttribute('aria-expanded', 'true');
    await expect(
        page.locator(EXPANDED).locator('.lp-comment-body'),
    ).toContainText('The first passage needs a number.');
    // The passage is highlighted level with the card, so the card does not
    // repeat it.
    await expect(
        page.locator(EXPANDED).locator('.lp-comment-quote'),
    ).toBeHidden();
    // The reply box is reachable while the card is open.
    await expect(page.locator(EXPANDED).locator('textarea')).toBeVisible();

    await markers.nth(2).click();
    await expect(page.locator(EXPANDED)).toHaveCount(1);
    await expect(markers.nth(0)).toHaveAttribute('aria-expanded', 'false');
    await expect(markers.nth(2)).toHaveAttribute('aria-expanded', 'true');

    // Clicking the open thread's own marker closes it again.
    await markers.nth(2).click();
    await expect(page.locator(EXPANDED)).toHaveCount(0);
});

test('Escape closes the open thread and returns focus to its marker', async ({
    page,
}) => {
    await seedThreeThreads(page);

    const marker = page.locator(MARKER).nth(1);
    await marker.click();
    await expect(page.locator(EXPANDED)).toHaveCount(1);

    await page.locator(`${EXPANDED} textarea`).focus();
    await page.keyboard.press('Escape');
    await expect(page.locator(EXPANDED)).toHaveCount(0);
    await expect(marker).toBeFocused();
});

test('hovering a marker highlights the passage it points at', async ({
    page,
}) => {
    await seedThreeThreads(page);

    const thread = page.locator(THREAD).nth(1);
    await expect(thread).not.toHaveClass(/lp-comment-thread--active/);

    await page.locator(MARKER).nth(1).hover();
    await expect(thread).toHaveClass(/lp-comment-thread--active/, {
        timeout: coverageScaled(5000),
    });
    // The pairing's other end: the anchor itself is painted.
    const painted = await page.evaluate(
        () => window.CSS.highlights.get('lp-anchor-hover')?.size ?? 0,
    );
    expect(painted).toBe(1);
});

test('below the rail breakpoint every thread is a whole card again', async ({
    page,
}) => {
    await seedThreeThreads(page);
    await expect(page.locator(MARKER).first()).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });

    // The rail is a margin idea. A thread the inline pass cannot place stays in
    // the margin, so the breakpoint has to gate the presentation as well.
    await expect(page.locator(`${MARKER}:visible`)).toHaveCount(0);
    await expect(page.locator('.lp-comment-body').first()).toBeVisible();
    await expect(page.locator('.lp-comment-quote').first()).toBeVisible();
});

test('hiding resolved threads closes the gap the markers left', async ({
    page,
}) => {
    await seedThreeThreads(page);

    const before = await markerTops(page);
    expect(before).toHaveLength(3);

    // The toggle is a button, and a button class declares a display that beats
    // the [hidden] rule the controller drives it with. Nothing is resolved yet,
    // so a toggle on screen here means that override came back.
    await expect(page.locator('.lp-review-actions__resolved')).toBeHidden();

    await page.locator(MARKER).nth(0).click();
    await page.getByRole('button', { name: 'Resolve', exact: true }).click();
    await expect(page.locator('.lp-comment-thread--resolved')).toHaveCount(1, {
        timeout: coverageScaled(10000),
    });

    const toggle = page.locator('.lp-review-actions__resolved');
    await expect(toggle).toBeVisible({ timeout: coverageScaled(5000) });
    await toggle.click();

    await expect(page.locator('.lp-comment-thread--resolved')).toBeHidden();
    const after = await markerTops(page);
    expect(after).toHaveLength(2);
    // The two survivors move up into the row the resolved marker held.
    expect(after[0]).toBeLessThan(before[1]);
});

/** The orphan group's rendered height, which its disclosure animates. */
function groupHeight(page: Page): Promise<number> {
    return page
        .locator('.lp-orphan-group')
        .evaluate((group) => (group as HTMLElement).offsetHeight);
}

/**
 * How far the first anchored card sits below the orphan group. Negative means
 * the card is under it.
 */
function cardGapBelowOrphans(page: Page): Promise<number | null> {
    return page.evaluate(() => {
        const orphans = document.querySelector('.lp-orphan-group');
        // The group holds a marker of its own, hidden, and a hidden element
        // measures as a zero box at the origin.
        const card = [...document.querySelectorAll('.lp-comment-thread')].find(
            (thread) => null === thread.closest('.lp-orphan-group'),
        );
        if (null === orphans || undefined === card) {
            return null;
        }

        return Math.round(
            card.getBoundingClientRect().top -
                orphans.getBoundingClientRect().bottom,
        );
    });
}

test('expanding the orphan group pushes the anchored cards below it', async ({
    page,
    reviewUrl,
}) => {
    await seedThreeThreads(page);

    // The revision drops the last two phrases, so their threads point at text
    // this version no longer holds and lead the column in the orphan group.
    // Two of them, because one is shorter than the anchored card's own offset
    // and the cards would clear it whatever the layout did.
    const documentId = reviewUrl.split('/')[4];
    const revised = await page.request.post(
        `/dev/review/${documentId}/revise`,
        {
            form: {
                markdown: DOCUMENT_MARKDOWN.replace(
                    ` and then a ${SECOND} and finally a ${THIRD}`,
                    '',
                ),
                description: 'Dropped the last two passages.',
            },
        },
    );
    expect(revised.status()).toBe(200);

    await page.goto(reviewUrl);
    const group = page.locator('.lp-orphan-group');
    await expect(group).toBeVisible({ timeout: coverageScaled(10000) });
    await expect(page.locator(`${MARKER}:visible`)).toHaveCount(1);
    const collapsed = await groupHeight(page);

    await page.locator('.lp-orphan-group__title').click();
    await expect(group).toHaveClass(/disclosure-open/);
    // The disclosure sets `height: auto` when its tween finishes, and the gap
    // below is still clear while the group is halfway open.
    await expect(page.locator('.lp-orphan-group__list')).toHaveAttribute(
        'style',
        /height:\s*auto/,
        { timeout: coverageScaled(5000) },
    );
    expect(await groupHeight(page)).toBeGreaterThan(collapsed);

    // The layout re-runs on its own here, and a review asked whether it does:
    // the group grows with no child-list change and no direct resize of the
    // element the observer watches.
    await expect
        .poll(() => cardGapBelowOrphans(page), {
            timeout: coverageScaled(5000),
        })
        .toBeGreaterThanOrEqual(0);
});
