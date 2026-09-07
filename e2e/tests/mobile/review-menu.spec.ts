/**
 * The review screen's mobile chrome at a 375px phone viewport with a touch
 * pointer.
 *
 * Below lg the top bar carries the drawer hamburger and one text block, and a
 * round button in the bottom right opens the review menu. Every test drives its
 * own user and document through the dev-only endpoints
 * (/dev/register-and-verify, /dev/seed/document), so nothing here touches
 * Mailpit.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eReviewMenu1!';

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

const MENU = '.lp-review-menu';
const TRIGGER = '.lp-review-menu__trigger';
const PANEL = '.lp-review-menu__panel';
const ROW = '.lp-review-menu__row';
const VERDICT = '.lp-review-menu__verdict';

// Long enough that a bar which gives the title no room clips it to a letter.
const DOCUMENT_TITLE =
    'Quarterly platform architecture review and migration plan';
const SHORT_TITLE = 'Scope';

const MARKDOWN =
    '## Scope\n\nThe first section.\n\n' +
    '## Risks\n\nThe second section.\n\n' +
    '## Rollout\n\nThe third section.\n';

async function devRegisterAndVerify(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Review Menu', email, password },
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
    // The generous timeout covers a cold PHP cache on the first login of a run.
    await expect(page).toHaveURL('/welcome', { timeout: 20000 });
}

interface Seeded {
    projectId: string;
    documentId: string;
}

async function seedDocument(
    page: Page,
    title: string,
    revisions?: string,
    markdown: string = MARKDOWN,
): Promise<Seeded> {
    const form: Record<string, string> = { title, markdown };
    if (revisions !== undefined) {
        form.revisions = revisions;
    }
    const response = await page.request.post('/dev/seed/document', { form });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return {
        projectId: body.projectId as string,
        documentId: body.documentId as string,
    };
}

/** How far an element's content spills past the box that holds it. */
async function overflowOf(page: Page, selector: string): Promise<number[]> {
    return page.$$eval(selector, (elements) =>
        elements.map((element) => element.scrollWidth - element.clientWidth),
    );
}

async function heightsOf(page: Page, selector: string): Promise<number[]> {
    return page.$$eval(selector, (elements) =>
        elements.map((element) => element.getBoundingClientRect().height),
    );
}

/**
 * Heights of the rows a reader can actually tap. The resolved toggle is in the
 * DOM from the start and revealed only once a resolved thread exists, so a
 * plain measurement reads it as a zero-height row.
 */
async function shownHeightsOf(page: Page, selector: string): Promise<number[]> {
    return page.$$eval(selector, (elements) =>
        elements
            .filter((element) => element.checkVisibility())
            .map((element) => element.getBoundingClientRect().height),
    );
}

const test = base.extend<{ seeded: Seeded }>({
    seeded: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+revmenu+${tag}+${RUN}@example.com`;
            await devRegisterAndVerify(page, email, PASSWORD);
            await login(page, email, PASSWORD);

            await use(await seedDocument(page, DOCUMENT_TITLE));
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: PHONE,
    hasTouch: true,
});

function reviewPath(seeded: Seeded): string {
    return `/projects/${seeded.projectId}/documents/${seeded.documentId}/review`;
}

test('the review bar is one row of the height of the desktop bar', async ({
    page,
    seeded,
}) => {
    await page.goto(reviewPath(seeded));

    const [barHeight] = await heightsOf(page, '.lp-topbar');
    expect(
        barHeight,
        'the review top bar grew a second row',
    ).toBeLessThanOrEqual(64);

    // The verdict buttons move into the review menu below lg. Left in the bar
    // they are what pushes it to three rows.
    await expect(page.locator('.lp-topbar__actions')).toBeHidden();

    for (const spill of await overflowOf(page, '.lp-topbar__lead')) {
        expect(
            spill,
            'the top bar lead spills past its box',
        ).toBeLessThanOrEqual(0);
    }

    // The defect the ellipsis hides: a lead squeezed by a neighbour reports no
    // overflow and still renders one letter and a dot.
    const widths = await page.evaluate(() => {
        const lead = document.querySelector('.lp-topbar__lead');
        const here = document.querySelector('.lp-topbar__here');
        return {
            lead: lead === null ? 0 : lead.getBoundingClientRect().width,
            here: here === null ? 0 : here.getBoundingClientRect().width,
        };
    });
    expect(widths.here).toBeGreaterThan(widths.lead * 0.8);

    await expect(page.locator('.lp-topbar__meta')).toHaveText(
        '0 open · 0 resolved',
    );
});

test('a title that fits the bar is not clipped', async ({ page }) => {
    const short = await seedDocument(page, SHORT_TITLE);
    await page.goto(reviewPath(short));

    for (const spill of await overflowOf(page, '.lp-topbar__here')) {
        expect(spill, 'a title that fits is still clipped').toBeLessThanOrEqual(
            0,
        );
    }
});

test('the menu button and every menu row clear the touch target', async ({
    page,
    seeded,
}) => {
    await page.goto(reviewPath(seeded));

    const trigger = page.locator(TRIGGER);
    await expect(trigger).toBeVisible();
    const [triggerBox] = await page.$$eval(TRIGGER, (elements) =>
        elements.map((element) => {
            const rect = element.getBoundingClientRect();
            return { width: rect.width, height: rect.height };
        }),
    );
    expect(triggerBox.width).toBeGreaterThanOrEqual(44);
    expect(triggerBox.height).toBeGreaterThanOrEqual(44);

    await trigger.tap();
    await expect(page.locator(PANEL)).toBeVisible();

    const rowHeights = await shownHeightsOf(page, `${ROW}, ${VERDICT}`);
    expect(rowHeights.length).toBeGreaterThan(0);
    for (const height of rowHeights) {
        expect(
            height,
            'a menu row is under the touch target',
        ).toBeGreaterThanOrEqual(44);
    }
});

test('the menu takes focus when it opens and gives it back when it closes', async ({
    page,
    seeded,
}) => {
    await page.goto(reviewPath(seeded));

    const trigger = page.locator(TRIGGER);
    await expect(page.locator(PANEL)).toBeHidden();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await trigger.tap();
    await expect(page.locator(PANEL)).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('.lp-review-menu__scrim')).toBeVisible();
    const inPanel = await page.evaluate(
        () =>
            document.activeElement?.closest('.lp-review-menu__panel') !== null,
    );
    expect(inPanel, 'focus stayed outside the open panel').toBe(true);

    await page.keyboard.press('Escape');
    await expect(page.locator(PANEL)).toBeHidden();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(trigger).toBeFocused();
});

test('Contents drills down inside the panel and the button walks back', async ({
    page,
    seeded,
}) => {
    await page.goto(reviewPath(seeded));

    await page.locator(TRIGGER).tap();
    const contents = page.locator(ROW, { hasText: 'Contents' });
    await expect(contents).toBeVisible();
    // Approved-of-total, the same count the desktop tab carries.
    await expect(contents).toContainText('0/3');

    await contents.tap();
    // The panel stays open and swaps its contents, rather than closing and
    // revealing a panel in the page.
    await expect(page.locator(PANEL)).toBeVisible();
    await expect(page.locator('.lp-review-menu__section')).toHaveCount(3);
    await expect(contents).toBeHidden();

    const back = page.getByRole('button', { name: 'Back' });
    await expect(back).toBeVisible();
    await back.tap();
    await expect(contents).toBeVisible();
    const focused = await page.evaluate(
        () =>
            document.activeElement?.closest('.lp-review-menu__panel') !== null,
    );
    expect(focused, 'the way back left focus outside the panel').toBe(true);
});

test('tapping a section jumps to it and closes the menu', async ({
    page,
    seeded,
}) => {
    await page.goto(reviewPath(seeded));

    await page.locator(TRIGGER).tap();
    await page.locator(ROW, { hasText: 'Contents' }).tap();
    await page.locator('.lp-review-menu__section').first().tap();

    await expect(page.locator(PANEL)).toBeHidden();
});

test('a read-only version offers no verdict rows at all', async ({ page }) => {
    const revised = await seedDocument(
        page,
        DOCUMENT_TITLE,
        JSON.stringify(['## Scope\n\nRevised.\n\n## Risks\n\nRevised.\n']),
    );
    await page.goto(`${reviewPath(revised)}/versions/1`);

    await page.locator(TRIGGER).tap();
    await expect(page.locator(PANEL)).toBeVisible();

    // Absent, not disabled: a control that cannot act is worse than no control.
    await expect(page.locator(VERDICT)).toHaveCount(0);
    await expect(page.locator('.lp-review-menu__rule')).toHaveCount(0);
});

test('the versions list keeps the way in to a diff', async ({ page }) => {
    const revised = await seedDocument(
        page,
        DOCUMENT_TITLE,
        JSON.stringify(['## Scope\n\nRevised.\n']),
    );
    await page.goto(reviewPath(revised));

    await page.locator(TRIGGER).tap();
    await page.locator(ROW, { hasText: 'Versions' }).tap();

    // The desktop versions panel is hidden below lg, so this row is the only
    // route from the review page to the comparison.
    const diff = page.getByRole('link', { name: 'What changed since v1' });
    await expect(diff).toBeVisible();
    await diff.tap();
    await expect(page.locator('.lp-version-banner')).toBeVisible();
});

test('a verdict leaves no button that opens an empty panel', async ({
    page,
}) => {
    const plain = await seedDocument(
        page,
        SHORT_TITLE,
        undefined,
        'A document with no headings at all.\n',
    );
    await page.goto(reviewPath(plain));

    await page.locator(TRIGGER).tap();
    await page.getByRole('button', { name: 'Approve' }).tap();
    await expect(page.locator('.lp-verdict-bar')).toBeVisible();

    // Nothing is left to choose: no headings, one version, no references, no
    // decisions, no verdict to give and no resolved thread to hide.
    await expect(page.locator(TRIGGER)).toBeHidden();
});

test('scrolling the paper closes the menu', async ({ page, seeded }) => {
    await page.goto(reviewPath(seeded));

    await page.locator(TRIGGER).tap();
    await expect(page.locator(PANEL)).toBeVisible();

    await page.locator('.lp-main').evaluate((element) => {
        element.scrollTop = 240;
    });

    await expect(page.locator(PANEL)).toBeHidden();
});

test('the desktop bar is untouched above lg', async ({ page, seeded }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto(reviewPath(seeded));

    const [barHeight] = await heightsOf(page, '.lp-topbar');
    expect(barHeight).toBe(64);

    await expect(page.locator('.lp-topbar__actions')).toBeVisible();
    await expect(page.locator(MENU)).toBeHidden();
    // The four document tabs stay in the page body on desktop.
    await expect(page.locator('.lp-doc-meta__tab').first()).toBeVisible();

    // The context has a coarse pointer, and the touch-target rules are the last
    // word on `display` unless they leave it alone.
    await expect(page.locator('.lp-topbar__menu')).toBeHidden();
    await expect(page.locator('.lp-sidebar__close')).toBeHidden();
});
