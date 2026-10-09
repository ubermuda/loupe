import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'e2e_password_123';
const ADDED = 'and a freshly added clause';

const FIRST = `# Rollout

The plan has one step.

<!-- decision: order -->

1. Ship the migration first
2. Ship the reader first

<!-- /decision -->

The closing paragraph stays the same.`;

const SECOND = FIRST.replace(
    'The plan has one step.',
    `The plan has one step ${ADDED}.`,
);

async function signedInReviewer(page: Page, slug: string): Promise<void> {
    const email = `e2e-newtext-${slug}-${RUN}@example.com`;
    const register = await page.request.post('/dev/register-and-verify', {
        form: {
            username: `nt${slug}${RUN}`.slice(0, 30),
            fullName: 'E2E New Text',
            email,
            password: PASSWORD,
        },
    });
    expect(register.status()).toBe(200);

    await suppressToolbar(page);
    await suppressWidget(page);
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
}

async function seedRevisedDocument(page: Page): Promise<string> {
    const response = await page.request.post('/dev/seed/document', {
        form: {
            title: 'New text highlight',
            markdown: FIRST,
            revisions: JSON.stringify([SECOND]),
        },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as {
        documentId: string;
        projectId: string;
    };

    return `/projects/${body.projectId}/documents/${body.documentId}/review`;
}

const paintedText = (page: Page) =>
    page.evaluate(() =>
        Array.from(window.CSS.highlights.get('lp-new-text') ?? []).map(
            (range) => range.toString(),
        ),
    );

test('the switch marks the added text and leaves the decisions working', async ({
    page,
}) => {
    await signedInReviewer(page, 'on');
    const reviewUrl = await seedRevisedDocument(page);
    await page.goto(reviewUrl);

    const newText = page.getByRole('switch', { name: 'Highlight new text' });
    await expect(newText).toHaveAttribute('aria-checked', 'false');
    expect(await paintedText(page)).toEqual([]);

    await newText.click();

    await expect(newText).toHaveAttribute('aria-checked', 'true');
    await expect
        .poll(() => paintedText(page), { timeout: coverageScaled(10000) })
        .toEqual([ADDED]);

    const radios = page.locator(
        '[data-decision-id="order"] input[type="radio"][data-decision-option]',
    );
    const saved = page.waitForResponse(
        (each) =>
            each.url().endsWith('/decisions/answer') &&
            each.request().method() === 'POST',
        { timeout: coverageScaled(15000) },
    );
    await radios.nth(1).check();
    expect((await saved).status()).toBe(200);
    await expect(radios.nth(1)).toBeChecked();
    expect(await paintedText(page)).toEqual([ADDED]);

    await newText.click();
    await expect(newText).toHaveAttribute('aria-checked', 'false');
    await expect.poll(() => paintedText(page)).toEqual([]);
});

test('the browser remembers the switch across a reload', async ({ page }) => {
    await signedInReviewer(page, 'remember');
    const reviewUrl = await seedRevisedDocument(page);
    await page.goto(reviewUrl);

    await page.getByRole('switch', { name: 'Highlight new text' }).click();
    await expect
        .poll(() => paintedText(page), { timeout: coverageScaled(10000) })
        .toEqual([ADDED]);

    await page.reload();

    await expect(
        page.getByRole('switch', { name: 'Highlight new text' }),
    ).toHaveAttribute('aria-checked', 'true');
    await expect
        .poll(() => paintedText(page), { timeout: coverageScaled(10000) })
        .toEqual([ADDED]);
});

test('the first version disables the switch and the comparison hides it', async ({
    page,
}) => {
    await signedInReviewer(page, 'first');
    const reviewUrl = await seedRevisedDocument(page);

    await page.goto(`${reviewUrl}/versions/1`);
    const newText = page.getByRole('switch', { name: /Highlight new text/ });
    await expect(newText).toHaveAttribute('aria-disabled', 'true');
    await newText.click({ force: true });
    await expect(newText).toHaveAttribute('aria-checked', 'false');
    expect(await paintedText(page)).toEqual([]);

    await page.goto(`${reviewUrl}/diff/1/2`);
    await expect(page.locator('.lp-review-doc')).toBeVisible();
    await expect(page.getByRole('switch')).toHaveCount(0);
});
