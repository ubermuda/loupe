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

async function seedDocument(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: {
            title: 'E2E Mobile Shell Document',
            markdown:
                '# Mobile\n\nA seeded document, so the filter bar renders.',
        },
    });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return body.projectId as string;
}

/** Widest rendered box against the window. A page that fits never scrolls sideways. */
async function horizontalOverflow(page: Page): Promise<number> {
    return page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
}

const test = base.extend<{ projectId: string }>({
    projectId: [
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
    projectId,
}) => {
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

test('the hamburger opens the sidebar drawer and Escape closes it', async ({
    page,
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/documents`);

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
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/documents`);
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
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/documents`);
    await page.getByRole('button', { name: 'Open navigation' }).tap();
    await expect(page.locator('.lp-shell')).toHaveAttribute('inert', '');

    await page.setViewportSize({ width: 1280, height: 900 });

    // The scrim and both close controls are display:none at lg, so an inert
    // shell would leave the desktop page unclickable with nothing to fix it.
    await expect(page.locator('.lp-shell')).not.toHaveAttribute('inert', '');
    await expect(page.getByRole('link', { name: 'Connect' })).toBeVisible();
});

test('tapping the scrim closes the drawer', async ({ page, projectId }) => {
    await page.goto(`/projects/${projectId}/documents`);

    await page.getByRole('button', { name: 'Open navigation' }).tap();
    await expect(page.locator(SIDEBAR)).toBeVisible();

    // Away from the centre: the scrim spans the viewport, and its centre point
    // lies under the open drawer, which would intercept the tap.
    await page.locator(SCRIM).tap({ position: { x: 340, y: 600 } });
    await expect(page.locator(SIDEBAR)).toBeHidden();
});

test('the drawer closes on the page a nav link goes to', async ({
    page,
    projectId,
}) => {
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
    projectId,
}) => {
    await page.goto(`/projects/${projectId}/documents`);

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
