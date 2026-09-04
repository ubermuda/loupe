/**
 * Browser coverage for per-section approval: approving a section from the
 * sections panel, and what a revision does to that approval.
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
    await expect(page).toHaveURL('/welcome');
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

/** Open the sections panel on the current review page. */
async function openSections(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Sections' }).click();
    await expect(page.locator(SECTIONS_PANEL)).toBeVisible();
}

/** The approve or withdraw button of one section row. */
function sectionRow(page: Page, label: string) {
    return page
        .locator(`${SECTIONS_PANEL} .lp-section-approvals__item`)
        .filter({ has: page.getByRole('link', { name: label, exact: true }) });
}

test.describe('per-section approval', () => {
    test('an approved section survives a revision that leaves it alone', async ({
        page,
        review,
    }) => {
        const { documentId, reviewUrl } = review;

        await openSections(page);
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('0 of 2 approved');

        await sectionRow(page, 'Alpha')
            .getByRole('button', { name: 'Approve section' })
            .click();
        // The page returns to the same URL, so wait for the count to change
        // rather than for the URL, which already matches.
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('1 of 2 approved');

        await sectionRow(page, 'Beta')
            .getByRole('button', { name: 'Approve section' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('2 of 2 approved');

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
        await openSections(page);
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('1 of 2 approved');
        await expect(
            sectionRow(page, 'Alpha').getByRole('button', {
                name: 'Withdraw approval',
            }),
        ).toBeVisible();
        await expect(
            sectionRow(page, 'Beta').getByRole('button', {
                name: 'Approve section',
            }),
        ).toBeVisible();
    });

    test('a reviewer can withdraw a section approval', async ({ page }) => {
        await openSections(page);
        await sectionRow(page, 'Alpha')
            .getByRole('button', { name: 'Approve section' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('1 of 2 approved');

        await openSections(page);
        await sectionRow(page, 'Alpha')
            .getByRole('button', { name: 'Withdraw approval' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('0 of 2 approved');
    });

    test('the whole-document verdict still works alongside section approval', async ({
        page,
    }) => {
        await openSections(page);
        await sectionRow(page, 'Alpha')
            .getByRole('button', { name: 'Approve section' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('1 of 2 approved');

        await page
            .getByRole('button', { name: 'Approve', exact: true })
            .click();
        await expect(page.locator('.lp-verdict-bar')).toBeVisible();

        await openSections(page);
        await expect(
            page.getByRole('button', { name: 'Sections' }),
        ).toContainText('1 of 2 approved');
    });
});
