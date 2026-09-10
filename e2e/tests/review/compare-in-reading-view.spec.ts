import { test, expect } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

/**
 * A comparison reads as the review page with a different pane, so its chrome is
 * one chip and one row of the metadata bar. The chip, the segmented view
 * switch, the jump controls and the picker are all driven by CSS or a Stimulus
 * controller, so none of them is reachable from PHPUnit.
 */
// Guest by default, and self-registering through the dev endpoints — the same
// shape as the other review specs, which seed their own document.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'e2e_password_123';

test('the comparison chrome is a chip and one row of the metadata bar', async ({
    page,
}) => {
    const email = `e2e-compare-${RUN}@example.com`;
    const register = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Compare', email, password: PASSWORD },
    });
    expect(register.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL('/welcome');
    await suppressToolbar(page);
    await suppressWidget(page);

    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Compared Plan',
            markdown:
                '# Plan\n\nThe rollout takes one step.\n\n## Risk\n\nLow.',
            revisions: JSON.stringify([
                '# Plan\n\nThe rollout takes two steps.\n\n## Risk\n\nLow.',
                '# Plan\n\nThe rollout takes three steps.\n\n## Risk\n\nModerate.',
            ]),
        },
    });
    expect(seeded.status()).toBe(201);
    const { projectId, documentId } = await seeded.json();
    const reviewPath = `/projects/${projectId}/documents/${documentId}/review`;

    await page.goto(`${reviewPath}/diff/1/3`);

    // The chip names the pair, and the banner it replaced is gone.
    const chip = page.locator('.lp-doc-meta__compare');
    await expect(chip).toContainText('Comparing v1 with v3');
    await expect(page.locator('.lp-version-banner')).toHaveCount(0);

    // Both controls sit on the one row, which is what lets the document start
    // near the top of the pane.
    const bar = page.locator('.lp-diff-bar');
    await expect(bar.locator('.lp-diff-views')).toHaveCount(1);
    await expect(bar.locator('.lp-diff-nav')).toHaveCount(1);

    // The switch says which view is current by weight and tint, not by colour
    // alone, and aria-current is what carries that to a screen reader.
    await expect(page.locator('.lp-diff-views__link[aria-current]')).toHaveText(
        'Document',
    );

    const counter = page.locator('.lp-diff-nav__count');
    await expect(counter).toHaveText('2 changes');

    // The jump controls are icon-only, so their accessible name comes from
    // aria-label rather than from any text in the button.
    const next = page.getByRole('button', { name: 'Next change' });
    const previous = page.getByRole('button', { name: 'Previous change' });
    await expect(next).toBeVisible();
    await next.click();
    await expect(counter).toHaveText('Change 1 of 2');
    await next.click();
    await expect(counter).toHaveText('Change 2 of 2');
    await previous.click();
    await expect(counter).toHaveText('Change 1 of 2');
    await expect(page.locator('.lp-diff__hunk--current')).toHaveCount(1);

    // The Markdown view is a plain navigation, and the switch follows it.
    await page.getByRole('link', { name: 'Markdown' }).click();
    await expect(page).toHaveURL(`${reviewPath}/diff/1/3?view=source`);
    await expect(page.locator('.lp-diff-views__link[aria-current]')).toHaveText(
        'Markdown',
    );
    await expect(page.locator('.lp-diff')).toBeVisible();

    // The picker starts another comparison from inside the versions panel, and
    // keeps the view the reader is on.
    await page.locator('[data-metadata-tabs-panel-param="versions"]').click();
    const panel = page.locator('[data-panel="versions"]');
    await expect(panel).toBeVisible();
    await panel.locator('#diff-from').selectOption('2');
    await panel.locator('#diff-to').selectOption('3');
    await panel.getByRole('button', { name: 'Compare' }).click();
    await expect(page).toHaveURL(`${reviewPath}/diff/2/3?view=source`);
    await expect(page.locator('.lp-doc-meta__compare')).toContainText(
        'Comparing v2 with v3',
    );

    // The stop control on the chip is the way back to the latest version.
    await page.getByRole('link', { name: 'Stop comparing' }).click();
    await expect(page).toHaveURL(reviewPath);
    await expect(page.locator('.lp-doc-meta__compare')).toHaveCount(0);
    await expect(page.locator('.lp-review-doc__prose')).toContainText(
        'three steps',
    );
});
