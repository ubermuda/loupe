import { expect, type Page } from '@playwright/test';
import {
    hubStubbedTest as base,
    suppressToolbar,
    suppressWidget,
} from '../fixtures';
import { coverageScaled } from '../timeouts';
import {
    PANELS_KEY,
    commentRow,
    openThread,
    panelButton,
    showPanel,
    threadOf,
} from './panels';

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
const ROW = '#comment-rows .lp-comment-row';
const COUNT = '[data-review-panels-target="openCount"]';

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
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
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
// Sign-in, seeding and three posted threads alone were measured at 30 seconds
// on a loaded CI runner, which overruns the default.
test.describe.configure({ timeout: 90000 });

/** Select a phrase in the prose the way a drag would, then post a comment on it. */
async function commentOn(
    page: Page,
    phrase: string,
    body: string,
): Promise<void> {
    await writeCommentOn(page, phrase, body);
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });
}

/** Selects a phrase, chooses Comment and fills the composer, with no post. */
async function writeCommentOn(
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
}

/** Viewport point a little inside the first line of a phrase in the prose. */
async function phrasePoint(
    page: Page,
    phrase: string,
): Promise<{ x: number; y: number }> {
    return page.evaluate((wanted: string) => {
        const docEl = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        )!;
        const walker = document.createTreeWalker(docEl, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const index = node.textContent?.indexOf(wanted) ?? -1;
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + wanted.length);
                const rect = range.getClientRects()[0];

                return { x: rect.left + 4, y: rect.top + rect.height / 2 };
            }
        }
        throw new Error(`phrase "${wanted}" not found in the prose`);
    }, phrase);
}

/** Size of a named CSS highlight, which paints a passage with no element. */
function highlightSize(page: Page, name: string): Promise<number> {
    return page.evaluate(
        (highlight: string) => window.CSS.highlights.get(highlight)?.size ?? 0,
        name,
    );
}

test('posting a comment opens the Comments panel on the new row', async ({
    page,
}) => {
    const comments = panelButton(page, 'Comments');
    await expect(comments).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('#review-panel-comments')).toBeHidden();

    await commentOn(page, FIRST, 'Opened by the post.');
    await expect(comments).toHaveAttribute('aria-pressed', 'true');
    await expect(commentRow(page, 'Opened by the post.')).toBeInViewport();
    expect(
        await page.evaluate(
            (key: string) => window.localStorage.getItem(key),
            PANELS_KEY,
        ),
    ).toBe(JSON.stringify(['decisions', 'comments']));
});

test('Cmd+Enter posts a comment while the Comments panel is closed', async ({
    page,
}) => {
    await expect(page.locator('#review-panel-comments')).toBeHidden();
    await expect(page.locator(COUNT)).toHaveText('0');
    const highlighted = await highlightSize(page, 'lp-anchor-pending');

    await writeCommentOn(page, SECOND, 'Posted from the keyboard.');
    await page.locator(COMPOSER_BODY).press('ControlOrMeta+Enter');

    await expect(page.locator(COMPOSER)).toBeHidden({
        timeout: coverageScaled(10000),
    });
    await expect(page.locator(COUNT)).toHaveText('1');
    await expect
        .poll(() => highlightSize(page, 'lp-anchor-pending'), {
            timeout: coverageScaled(5000),
        })
        .toBe(highlighted + 1);
    await expect(commentRow(page, 'Posted from the keyboard.')).toBeVisible();
});

test('clicking a highlighted passage opens its thread as a popover', async ({
    page,
}) => {
    await commentOn(page, SECOND, 'Found from the passage.');
    const thread = await openThread(page, 'Found from the passage.');
    await thread.getByRole('button', { name: 'Reply', exact: true }).click();
    await thread.getByRole('textbox').fill('A reply in the thread.');
    await thread
        .locator('.lp-comment-reply-form')
        .getByRole('button', { name: 'Reply', exact: true })
        .click();
    await expect(thread.locator('.lp-comment--reply')).toContainText(
        'A reply in the thread.',
        { timeout: coverageScaled(10000) },
    );
    await page.keyboard.press('Escape');
    await expect(thread).toBeHidden();

    const comments = panelButton(page, 'Comments');
    await comments.click();
    await expect(page.locator('#review-panel-comments')).toBeHidden();

    const point = await phrasePoint(page, SECOND);
    await page.mouse.click(point.x, point.y);

    await expect(thread).toBeVisible();
    await expect(comments).toHaveAttribute('aria-pressed', 'false');
    await expect(thread.locator('.lp-comment-author').first()).toHaveText(
        'E2E Rail Reviewer',
    );
    await expect(thread.locator('.lp-comment-body').first()).toHaveText(
        'Found from the passage.',
    );
    await expect(thread.locator('.lp-comment--reply')).toContainText(
        'A reply in the thread.',
    );
    for (const name of ['Delete', 'Reply', 'Resolve']) {
        await expect(
            thread.getByRole('button', { name, exact: true }),
        ).toBeVisible();
    }
    // The card opens under the passage, not in the panel column.
    const card = (await thread.boundingBox())!;
    expect(card.y).toBeGreaterThan(point.y);

    await page.keyboard.press('Escape');
    await expect(thread).toBeHidden();
});

test('a reply keeps the popover open on the updated thread', async ({
    page,
}) => {
    await commentOn(page, FIRST, 'A thread to answer.');
    const thread = await openThread(page, 'A thread to answer.');
    await thread.getByRole('button', { name: 'Reply', exact: true }).click();
    await thread.getByRole('textbox').fill('The answer.');
    await thread.getByRole('textbox').press('ControlOrMeta+Enter');

    await expect(thread.locator('.lp-comment--reply')).toContainText(
        'The answer.',
        { timeout: coverageScaled(10000) },
    );
    await expect(thread).toBeVisible();
    await expect(commentRow(page, 'A thread to answer.')).toHaveAttribute(
        'aria-expanded',
        'true',
    );
});

test('toolbar buttons switch panels in a fixed order and keep a reply draft', async ({
    page,
}) => {
    await commentOn(page, FIRST, 'Keep this discussion mounted.');
    const thread = await openThread(page, 'Keep this discussion mounted.');
    await thread.locator('[data-comment-reply-target=toggle]').click();
    const reply = thread.getByRole('textbox');
    await reply.fill('An unfinished reply');

    const comments = panelButton(page, 'Comments');
    const outline = panelButton(page, 'Outline');
    await outline.click();
    await expect(outline).toHaveAttribute('aria-pressed', 'true');
    // A click outside an open popover closes it.
    await expect(thread).toBeHidden();
    await expect(
        page
            .locator('.lp-review-panel:visible')
            .evaluateAll((panels) => panels.map((panel) => panel.id)),
    ).resolves.toEqual(['review-panel-comments', 'review-panel-outline']);

    await comments.click();
    await expect(comments).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('#review-panel-comments')).toBeHidden();
    await expect(page.locator('#review-panel-outline')).toBeVisible();

    await openThread(page, 'Keep this discussion mounted.');
    await expect(reply).toHaveValue('An unfinished reply');
});

test('the comment filter stays within narrow viewports', async ({ page }) => {
    await showPanel(page, 'Comments');
    const filter = page.locator('[data-review-panels-target="filter"]');
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await filter.locator('summary').click();
        const menu = filter.locator('.lp-review-filter__menu');
        await expect(menu).toBeVisible();
        const bounds = await menu.boundingBox();
        expect(bounds).not.toBeNull();
        expect(bounds!.x).toBeGreaterThanOrEqual(0);
        expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
        await page.keyboard.press('Escape');
        await expect(filter.locator('summary')).toBeFocused();
    }
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await filter.locator('summary').click();
    const enlargedBounds = (await filter
        .locator('.lp-review-filter__menu')
        .boundingBox())!;
    expect(enlargedBounds.x).toBeGreaterThanOrEqual(0);
    expect(enlargedBounds.x + enlargedBounds.width).toBeLessThanOrEqual(390);
});

test('the enlarged toolbar stays within a narrow viewport', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    for (const name of ['Decisions', 'Comments', 'Outline'] as const) {
        const bounds = (await panelButton(page, name).boundingBox())!;
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    }
});

test('general comments expose their initial and toggled disclosure state', async ({
    page,
}) => {
    await showPanel(page, 'Comments');
    await page
        .getByRole('button', {
            name: 'Comment on the whole document',
            exact: true,
        })
        .click();
    await page.locator(COMPOSER_BODY).fill('A general review comment.');
    await page.getByRole('button', { name: 'Post', exact: true }).click();
    const toggle = page.locator('.lp-general-comments__toggle');
    const panel = page.locator('#general-comments-list');
    await expect(panel).toContainText('A general review comment.', {
        timeout: coverageScaled(10000),
    });
    await expect(panel).toContainText('Whole document');
    await expect(panel).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(toggle).toHaveAttribute(
        'aria-controls',
        'general-comments-list',
    );

    // A general thread has no passage, so its card opens beside its row.
    const thread = await openThread(page, 'A general review comment.');
    await expect(thread.locator('.lp-comment-body')).toHaveText(
        'A general review comment.',
    );
    await page.keyboard.press('Escape');
    await expect(thread).toBeHidden();

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
    const row = commentRow(page, 'A discussion to filter.');
    const filter = page.locator('[data-review-panels-target="filter"]');
    const trigger = filter.locator('summary');
    const empty = page.getByText('No comments match this filter.', {
        exact: true,
    });

    await trigger.click();
    await expect(
        filter.getByRole('button', { name: 'Open 1', exact: true }),
    ).toBeVisible();
    await filter.getByRole('button', { name: 'Open 1', exact: true }).click();
    let thread = await openThread(page, 'A discussion to filter.');
    await thread.getByRole('button', { name: 'Resolve', exact: true }).click();
    await expect(thread).toHaveAttribute('data-anchor-status', 'resolved', {
        timeout: coverageScaled(10000),
    });
    await expect(row).toHaveAttribute('data-anchor-status', 'resolved');
    await expect(row).toBeHidden();
    await expect(empty).toBeVisible();

    await trigger.click();
    await expect(
        filter.getByRole('button', { name: 'Open 0', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await filter
        .getByRole('button', { name: 'Resolved 1', exact: true })
        .click();
    await expect(trigger).toBeFocused();
    await expect(row).toBeVisible();
    await expect(empty).toBeHidden();
    thread = await openThread(page, 'A discussion to filter.');
    await thread.getByRole('button', { name: 'Reopen', exact: true }).click();
    await expect(row).toHaveAttribute('data-anchor-status', 'pending', {
        timeout: coverageScaled(10000),
    });
    await expect(row).toBeHidden();
    await expect(empty).toBeVisible();

    await trigger.click();
    await expect(
        filter.getByRole('button', { name: 'Resolved 0', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await filter.getByRole('button', { name: 'All 1', exact: true }).click();
    await expect(row).toBeVisible();
    await trigger.click();
    await filter
        .getByRole('button', { name: 'Unanchored 0', exact: true })
        .click();
    await expect(row).toBeHidden();
    await expect(empty).toBeVisible();
    await trigger.click();
    await page.keyboard.press('Escape');
    await expect(filter).not.toHaveAttribute('open');
    await expect(trigger).toBeFocused();
});

async function seedThreeThreads(page: Page): Promise<void> {
    await commentOn(page, THIRD, 'Name the owner of the third.');
    await commentOn(page, FIRST, 'The first passage needs a number.');
    await commentOn(page, SECOND, 'The second one contradicts the first.');
    await expect(page.locator(ROW)).toHaveCount(3, {
        timeout: coverageScaled(10000),
    });
}

test('rows follow text order and show their bodies', async ({ page }) => {
    await seedThreeThreads(page);
    await expect(page.locator(`${ROW} .lp-comment-row__body`)).toHaveText([
        'The first passage needs a number.',
        'The second one contradicts the first.',
        'Name the owner of the third.',
    ]);
    await expect(page.locator(`${ROW} .lp-comment-row__quote`)).toHaveText([
        FIRST,
        SECOND,
        THIRD,
    ]);
    await expect(page.locator('.lp-comment-thread:visible')).toHaveCount(0);
});

test('hovering a row highlights the passage it points at', async ({ page }) => {
    await seedThreeThreads(page);
    await page.mouse.move(0, 0);
    await expect.poll(() => highlightSize(page, 'lp-anchor-hover')).toBe(0);

    await page.locator(ROW).nth(1).hover();
    await expect
        .poll(() => highlightSize(page, 'lp-anchor-hover'), {
            timeout: coverageScaled(5000),
        })
        .toBe(1);
});

test('a thread whose passage a revision removed opens from its struck row', async ({
    page,
    reviewUrl,
}) => {
    await commentOn(page, SECOND, 'This passage goes away.');

    const documentId = reviewUrl.split('/')[4];
    const revised = await page.request.post(
        `/dev/review/${documentId}/revise`,
        {
            form: {
                markdown: DOCUMENT_MARKDOWN.replace(
                    ` and then a ${SECOND}`,
                    '',
                ),
                description: 'Dropped the second passage.',
            },
        },
    );
    expect(revised.status()).toBe(200);

    await page.goto(reviewUrl);
    await showPanel(page, 'Comments');
    const group = page.locator('.lp-orphan-group');
    await expect(group).toBeVisible({ timeout: coverageScaled(10000) });
    await expect(group.locator('.lp-orphan-group__title')).toContainText(
        'No longer in the text',
    );
    const row = group.locator('.lp-comment-row').filter({
        hasText: 'This passage goes away.',
    });
    await expect(row).toBeVisible();
    await expect(row.locator('.lp-comment-row__quote')).toHaveText(SECOND);
    await expect(row.locator('.lp-comment-row__quote')).toHaveCSS(
        'text-decoration-line',
        'line-through',
    );

    const thread = await threadOf(page, 'This passage goes away.');
    await row.click();
    await expect(thread).toBeVisible();
    await expect(thread.locator('.lp-comment-body')).toHaveText(
        'This passage goes away.',
    );
});
