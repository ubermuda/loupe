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
const THREAD = '.lp-comment-thread';

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

test('margin tabs support keyboard navigation and preserve a reply draft', async ({
    page,
}) => {
    await commentOn(page, FIRST, 'Keep this discussion mounted.');
    const thread = page
        .locator(THREAD)
        .filter({ hasText: 'Keep this discussion mounted.' });
    await thread.locator('[data-comment-reply-target=toggle]').click();
    const reply = thread.getByRole('textbox');
    await reply.fill('An unfinished reply');

    const comments = page.getByRole('tab', { name: 'Comments', exact: true });
    const outline = page.getByRole('tab', { name: 'Outline', exact: true });
    const details = page.getByRole('tab', { name: 'Details', exact: true });
    const filter = page.locator('[data-review-margin-target="filter"]');
    await filter.locator('summary').click();
    await comments.focus();
    await page.keyboard.press('ArrowRight');
    await expect(outline).toBeFocused();
    await expect(outline).toHaveAttribute('aria-selected', 'true');
    await expect(comments).toHaveAttribute('tabindex', '-1');
    await expect(
        page.getByRole('tabpanel', { name: 'Outline', exact: true }),
    ).toBeVisible();
    await expect(filter).toBeHidden();

    await page.keyboard.press('End');
    await expect(details).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(comments).toBeFocused();
    await expect(reply).toHaveValue('An unfinished reply');
    await expect(filter).toBeVisible();
    await expect(filter).not.toHaveAttribute('open');

    await page.keyboard.press('ArrowLeft');
    await expect(details).toBeFocused();
    await page.keyboard.press('Home');
    await expect(comments).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(outline).not.toBeFocused();
    await expect(details).not.toBeFocused();
});

test('margin filter stays outside the tablist and within narrow viewports', async ({
    page,
}) => {
    const filter = page.locator('[data-review-margin-target="filter"]');
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await filter.locator('summary').click();
        const menu = filter.locator('.lp-review-margin-filter__menu');
        await expect(menu).toBeVisible();
        const bounds = await menu.boundingBox();
        expect(bounds).not.toBeNull();
        expect(bounds!.x).toBeGreaterThanOrEqual(0);
        expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
        const triggerBounds = (await filter.locator('summary').boundingBox())!;
        const commentsBounds = (await page
            .getByRole('tab', { name: 'Comments', exact: true })
            .boundingBox())!;
        expect(commentsBounds.x - triggerBounds.x - triggerBounds.width).toBe(
            4,
        );
        await page.keyboard.press('Escape');
        await expect(filter.locator('summary')).toBeFocused();
    }
    await expect(page.getByRole('tablist').locator('details')).toHaveCount(0);
    await expect(page.getByRole('tablist').getByRole('tab')).toHaveCount(4);
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await filter.locator('summary').click();
    const enlargedBounds = (await filter
        .locator('.lp-review-margin-filter__menu')
        .boundingBox())!;
    expect(enlargedBounds.x).toBeGreaterThanOrEqual(0);
    expect(enlargedBounds.x + enlargedBounds.width).toBeLessThanOrEqual(390);
});

test('enlarged margin tabs scroll within the document controls', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    const tablist = page.getByRole('tablist');
    const bounds = (await tablist.boundingBox())!;
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    const controls = page.locator('.lp-review-margin-tabs');
    const controlsLeft = (await controls.boundingBox())!.x;
    const expectSelectedVisible = async () => {
        const selected = tablist.locator('[aria-selected="true"]');
        await expect(selected).toBeFocused();
        const selectedBounds = (await selected.boundingBox())!;
        expect(selectedBounds.y).toBeGreaterThanOrEqual(0);
        const visibleBounds = (await tablist.boundingBox())!;
        expect((await controls.boundingBox())!.x).toBe(controlsLeft);
        expect(selectedBounds.x).toBeGreaterThanOrEqual(visibleBounds.x);
        expect(selectedBounds.x + selectedBounds.width).toBeLessThanOrEqual(
            visibleBounds.x + visibleBounds.width,
        );
        const labelBounds = (await selected.locator('span').boundingBox())!;
        expect(labelBounds.x).toBeGreaterThanOrEqual(selectedBounds.x);
        expect(labelBounds.x + labelBounds.width).toBeLessThanOrEqual(
            selectedBounds.x + selectedBounds.width,
        );
        const launcherBounds = (await page
            .locator('.lp-review-menu__trigger')
            .boundingBox())!;
        expect(selectedBounds.y + selectedBounds.height).toBeLessThanOrEqual(
            launcherBounds.y,
        );
    };
    await page.getByRole('tab', { name: 'Comments', exact: true }).focus();
    for (const key of [
        'End',
        'Home',
        'ArrowRight',
        'ArrowRight',
        'ArrowRight',
    ]) {
        await page.keyboard.press(key);
        await expectSelectedVisible();
    }
    for (const name of ['Comments', 'Details', 'Comments']) {
        await page.getByRole('tab', { name, exact: true }).click();
        await expectSelectedVisible();
    }
});

test('general comments expose their initial and toggled disclosure state', async ({
    page,
}) => {
    await page
        .locator('[data-action="comment-anchor#startUntargeted"]')
        .click();
    await page.locator(COMPOSER_BODY).fill('A general review comment.');
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    const toggle = page.locator('.lp-general-comments__toggle');
    const panel = page.locator('#general-comments-list');
    await expect(panel).toContainText('A general review comment.');
    await expect(panel).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(toggle).toHaveAttribute(
        'aria-controls',
        'general-comments-list',
    );
    await toggle.click();
    await expect(panel).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.click();
    await expect(panel).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
});

test('margin filters keep counts and visibility after thread updates', async ({
    page,
}) => {
    await commentOn(page, FIRST, 'A discussion to filter.');
    const thread = page
        .locator(THREAD)
        .filter({ hasText: 'A discussion to filter.' });
    const filter = page.locator('[data-review-margin-target="filter"]');
    const trigger = filter.locator('summary');
    const empty = page.getByText('No comments match this filter.', {
        exact: true,
    });

    await trigger.click();
    await expect(
        filter.getByRole('button', { name: 'Open 1', exact: true }),
    ).toBeVisible();
    await filter.getByRole('button', { name: 'Open 1', exact: true }).click();
    await thread.getByRole('button', { name: 'Resolve', exact: true }).click();
    await expect(thread).toHaveAttribute('data-anchor-status', 'resolved');
    await expect(thread).toBeHidden();
    await expect(empty).toBeVisible();

    await trigger.click();
    await expect(
        filter.getByRole('button', { name: 'Open 0', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await filter
        .getByRole('button', { name: 'Resolved 1', exact: true })
        .click();
    await expect(trigger).toBeFocused();
    await expect(thread).toBeVisible();
    await expect(empty).toBeHidden();
    await thread.getByRole('button', { name: 'Reopen', exact: true }).click();
    await expect(thread).toHaveAttribute('data-anchor-status', 'pending');
    await expect(thread).toBeHidden();
    await expect(empty).toBeVisible();

    await trigger.click();
    await expect(
        filter.getByRole('button', { name: 'Resolved 0', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await filter.getByRole('button', { name: 'All 1', exact: true }).click();
    await expect(thread).toBeVisible();
    await trigger.click();
    await filter
        .getByRole('button', { name: 'Unanchored 0', exact: true })
        .click();
    await expect(thread).toBeHidden();
    await expect(empty).toBeVisible();
    await trigger.click();
    await page.keyboard.press('Escape');
    await expect(filter).not.toHaveAttribute('open');
    await expect(trigger).toBeFocused();
});

/** Every visible thread's `top`, in DOM order. */
async function threadTops(page: Page): Promise<number[]> {
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
    // Each post streams in fresh cards with no inline `top`, stacked at the
    // margin's top until the rail's next animation frame places them.
    await expect(page.locator(`${THREAD}[style*="top"]`)).toHaveCount(3, {
        timeout: coverageScaled(10000),
    });
}

test('thread placement follows text order and reflows around reply drafts', async ({
    page,
}) => {
    await commentOn(page, THIRD, 'Last passage');
    await commentOn(page, FIRST, 'First passage');
    await commentOn(page, SECOND, 'Middle passage');

    const first = page.locator(THREAD).filter({ hasText: 'First passage' });
    const middle = page.locator(THREAD).filter({ hasText: 'Middle passage' });
    const last = page.locator(THREAD).filter({ hasText: 'Last passage' });
    await expect
        .poll(async () => {
            const firstBox = await first.boundingBox();
            const middleBox = await middle.boundingBox();
            const lastBox = await last.boundingBox();
            return (
                !!firstBox &&
                !!middleBox &&
                !!lastBox &&
                middleBox.y >= firstBox.y + firstBox.height + 14 &&
                lastBox.y >= middleBox.y + middleBox.height + 14
            );
        })
        .toBe(true);

    const disclosure = middle.locator('[data-controller=comment-reply]');
    await disclosure.locator('[data-comment-reply-target=toggle]').click();
    await disclosure
        .getByRole('textbox')
        .fill('Keep this draft while the rail reflows.');
    await expect
        .poll(async () => {
            const middleBox = await middle.boundingBox();
            const lastBox = await last.boundingBox();
            return middleBox && lastBox
                ? lastBox.y - middleBox.y - middleBox.height
                : -1;
        })
        .toBeGreaterThanOrEqual(14);
    const expandedTop = (await last.boundingBox())!.y;

    await disclosure.locator('[data-comment-reply-target=toggle]').click();
    await expect
        .poll(async () => (await last.boundingBox())!.y)
        .toBeLessThan(expandedTop);
    await disclosure.locator('[data-comment-reply-target=toggle]').click();
    await expect(disclosure.getByRole('textbox')).toHaveValue(
        'Keep this draft while the rail reflows.',
    );
});

test('nearby threads show their bodies and collapsed reply forms', async ({
    page,
}) => {
    await seedThreeThreads(page);
    await expect(page.locator('.lp-comment-body:visible')).toHaveCount(3);
    await expect(page.locator('.lp-comment-avatar:visible')).toHaveCount(3);
    await expect(page.locator('.lp-comment-quote:visible')).toHaveCount(0);
    await expect(page.locator(THREAD).locator('textarea:visible')).toHaveCount(
        0,
    );
});

test('hovering a thread highlights the passage it points at', async ({
    page,
}) => {
    await seedThreeThreads(page);

    const thread = page.locator(THREAD).nth(1);
    await expect(thread).not.toHaveClass(/lp-comment-thread--active/);

    await thread.hover();
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

    await page.setViewportSize({ width: 390, height: 844 });

    await expect(page.locator('.lp-comment-body').first()).toBeVisible();
    await expect(page.locator('.lp-comment-quote').first()).toBeHidden();
});

test('hiding resolved threads closes the gap the cards left', async ({
    page,
}) => {
    await seedThreeThreads(page);

    const before = await threadTops(page);
    expect(before).toHaveLength(3);

    await page
        .locator(THREAD)
        .first()
        .getByRole('button', { name: 'Resolve', exact: true })
        .click();
    await expect(page.locator('.lp-comment-thread--resolved')).toHaveCount(1, {
        timeout: coverageScaled(10000),
    });

    const filter = page.locator('[data-review-margin-target="filter"]');
    await filter.locator('summary').click();
    await filter.getByRole('button', { name: /^Open/ }).click();

    await expect(page.locator('.lp-comment-thread--resolved')).toBeHidden();

    // The hidden class lands at once and the rail repositions on the next
    // animation frame, so a single read can still catch the old rows. Poll
    // both reads, the way the orphan-group test below does.
    await expect
        .poll(() => threadTops(page), { timeout: coverageScaled(5000) })
        .toHaveLength(2);
    // The two survivors move up into the space the resolved card held.
    await expect
        .poll(async () => (await threadTops(page))[0], {
            timeout: coverageScaled(5000),
        })
        .toBeLessThan(before[1]);
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
    const toggle = group.getByRole('button', { expanded: false });
    await expect(toggle).toHaveAttribute('aria-controls', 'orphaned-threads');
    await expect(
        page.locator('.lp-comment-thread__detail:visible'),
    ).toHaveCount(1);
    const collapsed = await groupHeight(page);

    await page.locator('.lp-orphan-group__title').click();
    await expect(group).toHaveClass(/disclosure-open/);
    await expect(group.getByRole('button', { expanded: true })).toBeVisible();
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
