/**
 * The authenticated shell at a 375px phone viewport with a touch pointer.
 *
 * The sidebar is off-canvas below the lg breakpoint and the topbar carries the
 * hamburger that slides it in. Every test drives its own user and document
 * through the dev-only endpoints (/dev/register-and-verify, /dev/seed/document),
 * so nothing here touches Mailpit.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eMobileShell1!';

const PHONE = { width: 375, height: 812 };

const SIDEBAR = '[data-drawer-target="panel"]';
const SCRIM = '[data-drawer-target="scrim"]';

async function devRegisterAndVerify(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Mobile', email, password },
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
    // A fresh user owns no project, so the first-run wizard takes the landing.
    // seedDocument below creates the project the wizard would have created.
    // The generous timeout covers a cold PHP cache on the first login of a run,
    // which outran the 5s default and read as a rejected sign-in.
    await expect(page).toHaveURL('/welcome', { timeout: 20000 });
}

interface Seeded {
    projectId: string;
    documentId: string;
}

// A title long enough to be clipped by a row that does not give it room. A
// short one would let a broken row pass.
const DOCUMENT_TITLE =
    'Quarterly platform architecture review and migration plan';

async function seedDocument(page: Page): Promise<Seeded> {
    const response = await page.request.post('/dev/seed/document', {
        form: {
            title: DOCUMENT_TITLE,
            markdown:
                '# Mobile\n\nA seeded document, so the filter bar renders.',
        },
    });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return {
        projectId: body.projectId as string,
        documentId: body.documentId as string,
    };
}

/**
 * How far an element's content spills past the box that holds it. Text that is
 * clipped, ellipsised or painted over a neighbour reports a positive number
 * here and adds nothing to the document's own scrollWidth.
 */
async function overflowOf(page: Page, selector: string): Promise<number[]> {
    return page.$$eval(selector, (elements) =>
        elements.map((element) => element.scrollWidth - element.clientWidth),
    );
}

/** Widest rendered box against the window. A page that fits never scrolls sideways. */
async function horizontalOverflow(page: Page): Promise<number> {
    return page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
}

const test = base.extend<{ seeded: Seeded }>({
    seeded: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            const email = `e2e+mobile+${tag}+${RUN}@example.com`;

            await devRegisterAndVerify(page, email, PASSWORD);
            await login(page, email, PASSWORD);

            await use(await seedDocument(page));
        },
        { auto: true },
    ],
});

test.use({
    storageState: { cookies: [], origins: [] },
    viewport: PHONE,
    hasTouch: true,
});

test('no authenticated page scrolls sideways at 375px', async ({
    page,
    seeded,
}) => {
    const projectId = seeded.projectId;
    const paths = [
        '/projects',
        `/projects/${projectId}/documents`,
        `/projects/${projectId}/connect`,
        `/projects/${projectId}/edit`,
        '/account',
        '/about',
    ];

    for (const path of paths) {
        await page.goto(path);
        await expect(page.locator('.lp-topbar__menu')).toBeVisible();
        expect(
            await horizontalOverflow(page),
            `${path} overflows the viewport`,
        ).toBeLessThanOrEqual(0);
    }
});

test('a document title is readable rather than clipped to one letter', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);

    const title = page.locator('.lp-document-row__title').first();
    await expect(title).toBeVisible();
    await expect(title).toHaveText(DOCUMENT_TITLE);

    // The row is the app's index. A title squeezed to an ellipsis makes every
    // row look the same, and costs the list its whole job.
    for (const spill of await overflowOf(page, '.lp-document-row__title')) {
        expect(spill, 'a document title is clipped').toBeLessThanOrEqual(0);
    }
});

test('the review top bar does not print over its own actions', async ({
    page,
    seeded,
}) => {
    await page.goto(
        `/projects/${seeded.projectId}/documents/${seeded.documentId}/review`,
    );
    await expect(page.locator('.lp-topbar__actions')).toBeVisible();

    // The review screen is the only page with verdict actions, so it is the
    // only one that can squeeze the lead. The element rectangles do not
    // overlap: the lead's content escapes a collapsed box, and only a
    // scrollWidth reading catches that.
    for (const spill of await overflowOf(page, '.lp-topbar__lead')) {
        expect(
            spill,
            'the top bar lead spills past its box',
        ).toBeLessThanOrEqual(0);
    }
    for (const spill of await overflowOf(page, '.lp-topbar__trail')) {
        expect(
            spill,
            'the breadcrumb trail spills past its box',
        ).toBeLessThanOrEqual(0);
    }
});

test('the paper reaches the window edge below lg', async ({ page, seeded }) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);

    // The shell's right and bottom padding frames the paper against the sidebar
    // on desktop. With the sidebar out of flow nothing balances it on the left,
    // so it reads as a stray black strip.
    const box = await page.locator('.lp-main').evaluate((element) => {
        const rect = element.getBoundingClientRect();
        return {
            right: Math.round(rect.right),
            bottom: Math.round(rect.bottom),
            innerWidth: window.innerWidth,
            innerHeight: window.innerHeight,
        };
    });
    expect(box.right).toBe(box.innerWidth);
    expect(box.bottom).toBe(box.innerHeight);
});

test('the hamburger opens the sidebar drawer and Escape closes it', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);

    const sidebar = page.locator(SIDEBAR);
    const trigger = page.getByRole('button', { name: 'Open navigation' });

    await expect(sidebar).toBeHidden();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator(SCRIM)).toBeHidden();

    await trigger.tap();
    await expect(sidebar).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator(SCRIM)).toBeVisible();
    // Focus enters the drawer, rather than being left on a shell that is now
    // inert. Restore on close is asserted below and does not cover this.
    await expect(
        page.getByRole('button', { name: 'Close navigation' }),
    ).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(sidebar).toBeHidden();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    // Focus returns to what opened the drawer, not to the document body.
    await expect(trigger).toBeFocused();
});

test('the open drawer keeps Tab off the page behind it', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);
    await page.getByRole('button', { name: 'Open navigation' }).tap();
    await expect(page.locator(SIDEBAR)).toBeVisible();

    // The scrim stops a tap on the covered page; this proves the keyboard
    // cannot reach it either.
    for (let step = 0; step < 20; step += 1) {
        await page.keyboard.press('Tab');
        const escaped = await page.evaluate(() =>
            Boolean(document.activeElement?.closest('.lp-shell')),
        );
        expect(escaped, `Tab ${step + 1} landed behind the drawer`).toBe(false);
    }
});

test('growing the window past lg releases the drawer', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);
    await page.getByRole('button', { name: 'Open navigation' }).tap();
    await expect(page.locator('.lp-shell')).toHaveAttribute('inert', '');

    await page.setViewportSize({ width: 1280, height: 900 });

    // The scrim and both close controls are display:none at lg, so an inert
    // shell would leave the desktop page unclickable with nothing to fix it.
    await expect(page.locator('.lp-shell')).not.toHaveAttribute('inert', '');
    await expect(page.getByRole('link', { name: 'Connect' })).toBeVisible();
});

test('tapping the scrim closes the drawer', async ({ page, seeded }) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);

    await page.getByRole('button', { name: 'Open navigation' }).tap();
    await expect(page.locator(SIDEBAR)).toBeVisible();

    // Away from the centre: the scrim spans the viewport, and its centre point
    // lies under the open drawer, which would intercept the tap.
    await page.locator(SCRIM).tap({ position: { x: 340, y: 600 } });
    await expect(page.locator(SIDEBAR)).toBeHidden();
});

test('the drawer closes on the page a nav link goes to', async ({
    page,
    seeded,
}) => {
    const projectId = seeded.projectId;
    await page.goto(`/projects/${projectId}/documents`);

    await page.getByRole('button', { name: 'Open navigation' }).tap();
    const sidebar = page.locator(SIDEBAR);
    await expect(sidebar).toBeVisible();

    await sidebar.getByRole('link', { name: 'Connect' }).tap();

    await expect(page).toHaveURL(`/projects/${projectId}/connect`);
    await expect(page.locator('.lp-connect')).toBeVisible();
    await expect(sidebar).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Open navigation' }),
    ).toHaveAttribute('aria-expanded', 'false');
});

test('no touch control renders below the 16px iOS zoom threshold', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);

    for (const selector of ['.lp-filter-input', '.lp-filter-select']) {
        const control = page.locator(selector).first();
        await expect(control).toBeVisible();
        expect(
            await control.evaluate((element) =>
                parseFloat(getComputedStyle(element).fontSize),
            ),
            `${selector} is under 16px`,
        ).toBeGreaterThanOrEqual(16);
    }

    // The composer and the reply form mount only on the review screen.
    // Measure the compiled rule itself instead.
    const sizes = await page.evaluate(() => {
        const probe = document.createElement('div');
        probe.innerHTML =
            '<div class="lp-comment-composer"><textarea></textarea></div>' +
            '<div class="lp-comment-reply-form"><textarea></textarea></div>';
        document.body.appendChild(probe);
        const measured = Array.from(probe.querySelectorAll('textarea')).map(
            (element) => parseFloat(getComputedStyle(element).fontSize),
        );
        probe.remove();
        return measured;
    });

    expect(sizes).toHaveLength(2);
    for (const size of sizes) {
        expect(size).toBeGreaterThanOrEqual(16);
    }
});
