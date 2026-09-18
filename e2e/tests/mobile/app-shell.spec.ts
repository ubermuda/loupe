/**
 * The authenticated shell at a 375px phone viewport with a touch pointer.
 *
 * The sidebar is off-canvas at 780px and below, and the topbar carries the
 * hamburger that slides it in. Every test drives its own user and document
 * through the dev-only endpoints (/dev/register-and-verify, /dev/seed/document),
 * so nothing here touches Mailpit.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { expectFilterFocusRingVisible } from '../helpers';

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

test('workspace component styles allow utility overrides', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);
    await page.evaluate(() => {
        const workspace = document.createElement('div');
        workspace.className = 'lp-core-inbox';
        const section = document.createElement('section');
        section.id = 'cascade-probe';
        section.className = 'lp-inbox-section--queue';
        section.textContent = 'Component cascade probe';
        workspace.appendChild(section);
        document.body.appendChild(workspace);
    });
    const section = page.locator('#cascade-probe');
    await expect(section).toHaveCSS('display', 'flex');
    await section.evaluate((element) => element.classList.add('hidden'));
    await expect(section).toBeHidden();
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
        '/account/profile',
        '/account/api-tokens',
        '/account/data',
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

test('the sidebar stays in flow above the 780px shell breakpoint', async ({
    page,
    seeded,
}) => {
    await page.setViewportSize({ width: 950, height: 900 });
    await page.goto(`/projects/${seeded.projectId}/documents`);

    await expect(page.locator(SIDEBAR)).toBeVisible();
    await expect(page.locator('.lp-topbar__menu')).toBeHidden();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
});

test('account panels fit narrow screens and enlarged text', async ({
    page,
}) => {
    const sections = [
        { path: '/account/profile', panelCount: 1 },
        { path: '/account/api-tokens', panelCount: 1 },
        { path: '/account/data', panelCount: 2 },
    ];
    const panels = page.locator(
        '.lp-settings-content > section:not([data-testid="billing-section"])',
    );
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const { path, panelCount } of sections) {
            await page.goto(path);
            await expect(panels).toHaveCount(panelCount);
            for (const panel of await panels.all()) {
                const bounds = (await panel.boundingBox())!;
                expect(bounds.x).toBeGreaterThanOrEqual(0);
                expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
                expect(
                    await panel.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                ).toBeLessThanOrEqual(1);
            }
            const navItems = page.locator('.lp-settings-nav__item');
            await expect(navItems).toHaveCount(3);
            for (const item of await navItems.all()) {
                const icon = (await item.locator('svg').boundingBox())!;
                const label = (await item.boundingBox())!;
                // One line of text: a label wrapped under its icon doubles the height.
                expect(label.height).toBeLessThan(icon.height * 3);
            }
        }
    }
    for (const { path, panelCount } of sections) {
        await page.goto(path);
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await expect(panels).toHaveCount(panelCount);
        for (const panel of await panels.all()) {
            const bounds = (await panel.boundingBox())!;
            expect(bounds.x).toBeGreaterThanOrEqual(0);
            expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
            expect(
                await panel.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            ).toBeLessThanOrEqual(1);
            for (const button of await panel.getByRole('button').all()) {
                await button.scrollIntoViewIfNeeded();
                await expect(button).toBeInViewport({ ratio: 1 });
                expect(
                    await button.evaluate(
                        (element) => element.scrollWidth - element.clientWidth,
                    ),
                ).toBeLessThanOrEqual(1);
            }
        }
    }
    await expect(page.getByTestId('export-section')).not.toContainText(
        'The download link was sent to your email.',
    );
});

test('project settings fit narrow screens and enlarged text', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/edit`);
    const panels = page.locator('.lp-settings-panel-card');
    await expect(panels).toHaveCount(2);
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const panel of await panels.all()) {
            const bounds = (await panel.boundingBox())!;
            expect(bounds.x).toBeGreaterThanOrEqual(0);
            expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
            expect(
                await panel.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            ).toBeLessThanOrEqual(1);
        }
    }
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    for (const panel of await panels.all()) {
        const bounds = (await panel.boundingBox())!;
        expect(bounds.x).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
        expect(
            await panel.evaluate(
                (element) => element.scrollWidth - element.clientWidth,
            ),
        ).toBeLessThanOrEqual(1);
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
    // The verdict actions leave the bar below lg and become rows of the review
    // menu. They are what used to squeeze the lead.
    await expect(page.locator('.lp-topbar__actions')).toBeHidden();

    // The element rectangles do not overlap: the lead's content escapes a
    // collapsed box, and only a scrollWidth reading catches that.
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
    await expect(
        page.getByRole('heading', { name: 'Documents' }),
    ).toBeVisible();
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

    await sidebar.getByRole('link', { name: 'Agents' }).tap();

    await expect(page).toHaveURL(`/projects/${projectId}/agents`);
    await expect(
        page.getByRole('heading', { name: 'Your crew' }),
    ).toBeVisible();
    await expect(sidebar).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Open navigation' }),
    ).toHaveAttribute('aria-expanded', 'false');
});

test('search and status stay aligned across workspace widths', async ({
    page,
    seeded,
}) => {
    for (const path of ['documents', 'worker-runs?search=missing']) {
        await page.goto(`/projects/${seeded.projectId}/${path}`);
        const search = page.locator('.lp-filter-input');
        const status = page.locator('.lp-filter-select').first();
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await expect(search).toBeVisible();
            await expect(status).toBeVisible();
            const searchBox = await search.boundingBox();
            const statusBox = await status.boundingBox();
            expect(searchBox).not.toBeNull();
            expect(statusBox).not.toBeNull();
            expect(statusBox!.y).toBe(searchBox!.y);
            expect(statusBox!.height).toBe(searchBox!.height);
            expect(statusBox!.x - searchBox!.x - searchBox!.width).toBe(8);
            expect(statusBox!.x + statusBox!.width).toBeLessThanOrEqual(width);
            expect(searchBox!.width).toBeGreaterThan(80);
        }
        await search.fill('no matching work');
        await expect(page).toHaveURL(/search=no\+matching\+work/);
        await expect(search).toHaveValue('no matching work');
        await expect(status).toBeVisible();
    }
});

test('enlarged document creation action remains reachable', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);
    await page.evaluate(() => {
        document.documentElement.style.fontSize = '200%';
    });
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect(
            page.getByRole('button', {
                name: 'New document',
                exact: true,
            }),
        ).toBeInViewport({ ratio: 1 });
    }
});

test('enlarged workspace filters remain reachable', async ({
    page,
    seeded,
}) => {
    for (const path of ['documents', 'worker-runs?search=missing']) {
        await page.goto(`/projects/${seeded.projectId}/${path}`);
        await page.evaluate(() => {
            document.documentElement.style.fontSize = '200%';
        });
        const search = page.locator('.lp-filter-input');
        const status = page.locator('.lp-filter-select').first();
        const filters = page.locator('.lp-filter-primary');
        for (const width of [1440, 1150, 950, 780, 390]) {
            await page.setViewportSize({ width, height: 1000 });
            const searchBounds = await search.boundingBox();
            const statusBounds = await status.boundingBox();
            expect(searchBounds).not.toBeNull();
            expect(statusBounds).not.toBeNull();
            expect(statusBounds!.y).toBe(searchBounds!.y);
            expect(statusBounds!.height).toBe(searchBounds!.height);
            expect(
                statusBounds!.x - searchBounds!.x - searchBounds!.width,
            ).toBe(16);
            await search.focus();
            await expect(search).toBeInViewport({ ratio: 1 });
            await expectFilterFocusRingVisible(search, filters);
            await search.press('Tab');
            await expect(status).toBeFocused();
            await expect(status).toBeInViewport({ ratio: 1 });
            await expectFilterFocusRingVisible(status, filters);
            await status.press('Shift+Tab');
            await expect(search).toBeFocused();
            await expect(search).toBeInViewport({ ratio: 1 });
            await expectFilterFocusRingVisible(search, filters);
        }
    }
});

test('no touch control renders below the 16px iOS zoom threshold', async ({
    page,
    seeded,
}) => {
    await page.goto(`/projects/${seeded.projectId}/documents`);

    for (const selector of ['.lp-filter-input', '.lp-filter-select']) {
        const control = page.locator(selector).first();
        await expect(control).toBeVisible();
        await expect(control).toHaveCSS('height', '44px');
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
