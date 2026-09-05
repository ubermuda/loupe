/**
 * Browser coverage for per-section approval: the button beside each heading,
 * and what a revision does to the approvals it recorded.
 *
 * The round trip is the point. A section the revision leaves alone stays
 * approved; a section it rewrites comes back unapproved.
 *
 * Every test drives its own user and document through the dev-only endpoints —
 * /dev/register-and-verify, /dev/seed/document and /dev/review/{id}/revise.
 */

import { test as base, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

const RUN = Date.now();
const PASSWORD = 'E2eSectionApproval1!';

const V1 = `## Alpha

Alpha body stays exactly as it is.

## Beta

Beta body gets rewritten by the revision.`;

const V2 = `## Alpha

Alpha body stays exactly as it is.

## Beta

Beta body reads completely differently now.`;

const SECTIONS_PANEL = '[data-panel="sections"]';
const HEADING_CONTROL =
    '[data-comment-anchor-target="doc"] [data-section-approve]';

async function devRegisterAndVerify(page: Page, email: string): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Section Reviewer', email, password: PASSWORD },
    });
    expect(response.status()).toBe(200);
}

async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // A cold PHP cache makes the first sign-in of a run slow, so this waits
    // longer than the default expect timeout.
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

async function seedDocument(
    page: Page,
): Promise<{ documentId: string; projectId: string }> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'E2E Section Approval Document', markdown: V1 },
    });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return {
        documentId: body.documentId as string,
        projectId: body.projectId as string,
    };
}

interface SeededReview {
    documentId: string;
    reviewUrl: string;
}

/**
 * Registers a user, logs in and seeds a two-section document. Each test gets
 * its own user and document, so nothing here can disturb a sibling test.
 */
const test = base.extend<{ review: SeededReview }>({
    review: [
        async ({ page }, use, testInfo) => {
            await suppressToolbar(page);
            await suppressWidget(page);

            const tag = testInfo.testId.replace(/[^a-z0-9]/gi, '');
            await devRegisterAndVerify(
                page,
                `e2e+sections+${tag}+${RUN}@example.com`,
            );
            await login(page, `e2e+sections+${tag}+${RUN}@example.com`);

            const { documentId, projectId } = await seedDocument(page);
            const reviewUrl = `/projects/${projectId}/documents/${documentId}/review`;

            await page.goto(reviewUrl);
            await use({ documentId, reviewUrl });
        },
        { auto: true },
    ],
});

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

// A test here registers, logs in, seeds, and then makes up to four writes that
// each redirect and re-render. One such write was measured at six seconds
// against a php-fpm shared with every other worktree, which overruns the
// 30-second default.
test.describe.configure({ timeout: 90000 });

/** Open the sections panel on the current review page. */
async function openSections(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Sections' }).click();
    await expect(page.locator(SECTIONS_PANEL)).toBeVisible();
}

/** One row of the sections panel, found by its heading label. */
function sectionRow(page: Page, label: string) {
    return page
        .locator(`${SECTIONS_PANEL} .lp-section-approvals__item`)
        .filter({ has: page.getByRole('link', { name: label, exact: true }) });
}

/** The tab, which carries the running count and stays visible on every render. */
function sectionsTab(page: Page) {
    return page.getByRole('button', { name: 'Sections' });
}

/**
 * Waits for the running count the tab carries.
 *
 * Every press redirects back to the page it came from, so the URL cannot say
 * the write landed and the count is the signal. The timeout is generous because
 * the whole write, redirect and re-render was measured at just over six seconds
 * against a php-fpm shared with every other worktree.
 */
async function expectSectionCount(page: Page, count: string): Promise<void> {
    await expect(sectionsTab(page)).toContainText(count, { timeout: 20000 });
}

/**
 * The approve button beside one heading, inside the document. It is the only
 * place a reviewer acts; the panel is an overview and carries no control.
 */
function headingControl(page: Page, headingId: string) {
    return page.locator(
        `${HEADING_CONTROL}[data-section-approve="${headingId}"]`,
    );
}

test.describe('per-section approval', () => {
    test('an approved section survives a revision that leaves it alone', async ({
        page,
        review,
    }) => {
        const { documentId, reviewUrl } = review;

        await expectSectionCount(page, '0 of 2 approved');

        await headingControl(page, 'heading-alpha').click();
        // The page returns to the same URL, so wait for the running count to
        // change rather than for the URL, which already matches.
        await expectSectionCount(page, '1 of 2 approved');

        await headingControl(page, 'heading-beta').click();
        await expectSectionCount(page, '2 of 2 approved');

        const revised = await page.request.post(
            `/dev/review/${documentId}/revise`,
            {
                form: {
                    markdown: V2,
                    description: 'Rewrote the Beta section.',
                },
            },
        );
        expect(revised.status()).toBe(200);
        expect(await revised.json()).toMatchObject({
            sectionsCarried: 1,
            sectionsDropped: 1,
        });

        await page.goto(reviewUrl);
        await expectSectionCount(page, '1 of 2 approved');

        await expect(headingControl(page, 'heading-alpha')).toHaveAttribute(
            'aria-pressed',
            'true',
            { timeout: 20000 },
        );
        await expect(headingControl(page, 'heading-beta')).toHaveAttribute(
            'aria-pressed',
            'false',
        );

        await openSections(page);
        await expect(sectionRow(page, 'Alpha')).toContainText('Approved');
        await expect(sectionRow(page, 'Beta')).toContainText('Not approved');
    });

    test('a reviewer can withdraw a section approval', async ({ page }) => {
        await headingControl(page, 'heading-alpha').click();
        await expectSectionCount(page, '1 of 2 approved');
        await expect(headingControl(page, 'heading-alpha')).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        await headingControl(page, 'heading-alpha').click();
        await expectSectionCount(page, '0 of 2 approved');
        await expect(headingControl(page, 'heading-alpha')).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });

    test('the whole-document verdict still works alongside section approval', async ({
        page,
    }) => {
        await headingControl(page, 'heading-alpha').click();
        await expectSectionCount(page, '1 of 2 approved');

        await page
            .getByRole('button', { name: 'Approve', exact: true })
            .click();
        // Same generous wait as the count above: this is a second write,
        // a redirect and a re-render on the shared container.
        await expect(page.locator('.lp-verdict-bar')).toBeVisible({
            timeout: 20000,
        });
        await expectSectionCount(page, '1 of 2 approved');
    });

    test('the panel reports what the heading control did, and offers no control of its own', async ({
        page,
    }) => {
        await expect(page.locator(HEADING_CONTROL)).toHaveCount(2);

        await openSections(page);
        await expect(sectionRow(page, 'Alpha')).toContainText('Not approved');
        await expect(page.locator(`${SECTIONS_PANEL} button`)).toHaveCount(0);

        await headingControl(page, 'heading-alpha').click();
        await expectSectionCount(page, '1 of 2 approved');

        await openSections(page);
        await expect(sectionRow(page, 'Alpha')).toContainText('Approved');
        await expect(page.locator(`${SECTIONS_PANEL} button`)).toHaveCount(0);
    });

    test('the heading control carries its name on an attribute, not as text', async ({
        page,
    }) => {
        const control = headingControl(page, 'heading-beta');

        await expect(control).toHaveAttribute(
            'aria-label',
            'Approve section Beta',
        );
        // Empty on purpose: text here would move every comment anchor below it.
        await expect(control).toHaveText('');
    });
});
