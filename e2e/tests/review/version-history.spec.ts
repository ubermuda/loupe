import { test, expect } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

// Guest by default, and self-registering through the dev endpoints — the same
// shape as the other review specs, which seed their own document.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'e2e_password_123';

test('the History tab compares two distant versions', async ({ page }) => {
    const email = `e2e-history-${RUN}@example.com`;
    const register = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E History', email, password: PASSWORD },
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
            title: 'Versioned Plan',
            markdown: '# Plan\n\nThe rollout takes one step.',
            revisions: JSON.stringify([
                '# Plan\n\nThe rollout takes two steps.',
                '# Plan\n\nThe rollout takes three steps.',
                '# Plan\n\nThe rollout takes four careful steps.',
            ]),
        },
    });
    expect(seeded.status()).toBe(201);
    const { projectId, documentId } = await seeded.json();

    await page.goto(`/projects/${projectId}/documents/${documentId}/review`);
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(
        page.locator('.lp-review-view-tabs__item[aria-current="page"]'),
    ).toHaveText('History');
    await expect(
        page.getByRole('heading', { name: 'Versioned Plan', exact: true }),
    ).toBeVisible();
    await expect(page.locator('.lp-review-doc__version')).toHaveText('v4');
    await expect(page.locator('.lp-review-margin-tabs')).toHaveCount(0);

    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    const reviewDialog = page.getByRole('dialog', { name: 'Finish review' });
    await reviewDialog
        .getByRole('radio', { name: 'Approve', exact: true })
        .check();
    await reviewDialog
        .getByLabel('Review note', { exact: true })
        .fill('Ready for the rollout.');
    await reviewDialog.getByRole('button', { name: 'Submit review' }).click();
    await expect(page.locator('.lp-verdict-bar--approved')).toBeVisible();
    await page
        .locator('.lp-verdict-bar')
        .getByRole('button', { name: 'Undo', exact: true })
        .click();
    await expect(page.locator('.lp-verdict-bar')).toHaveCount(0);

    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(page).toHaveURL(
        `/projects/${projectId}/documents/${documentId}/review/history`,
    );
    await expect(
        page.getByRole('heading', { name: 'Version history' }),
    ).toBeVisible();
    await expect(page.locator('.lp-history__row')).toHaveCount(4);
    const currentVersion = page.locator(
        '.lp-history__row[data-version-number="4"]',
    );
    await expect(currentVersion.locator('.lp-history__verdict')).toHaveText([
        'Approved',
        'Verdict withdrawn',
    ]);
    await expect(currentVersion).toContainText('Ready for the rollout.');
    await expect(currentVersion).toContainText('E2E History');
    await expect(
        page.locator('.lp-history__row[data-version-number="3"]'),
    ).toContainText('No reviews for this version.');
    await page.reload();
    await expect(currentVersion.locator('.lp-history__review')).toHaveCount(2);

    // v1 against v4: a pair the per-version compare controls never offer.
    await page.locator('#history-compare-from').selectOption('1');
    await page.locator('#history-compare-to').selectOption('4');
    await page.getByRole('button', { name: 'Compare these two' }).click();

    await expect(page).toHaveURL(
        `/projects/${projectId}/documents/${documentId}/review/diff/1/4`,
    );
    await expect(page.locator('.lp-doc-meta__compare')).toContainText(
        'Comparing v1 with v4',
    );
    await expect(
        page.locator('.lp-diff__mark--deleted', { hasText: 'one step' }),
    ).toHaveCount(1);
    await expect(
        page.locator('.lp-diff__mark--inserted', {
            hasText: 'four careful steps',
        }),
    ).toHaveCount(1);

    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Version history' }),
    ).toBeVisible();
    await expect(page.locator('html')).not.toHaveAttribute(
        'data-turbo-preview',
    );
    await expect(page.locator('html')).not.toHaveAttribute('aria-busy', 'true');
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    const reviseDialog = page.getByRole('dialog', { name: 'Revise document' });
    await expect(reviseDialog.getByLabel('Title', { exact: true })).toHaveValue(
        'Versioned Plan',
    );
    await reviseDialog
        .getByLabel('Markdown', { exact: true })
        .fill('# Plan\n\nThe rollout takes five verified steps.');
    await reviseDialog
        .getByLabel('Revision note', { exact: true })
        .fill('Add the verification step.');
    await reviseDialog
        .getByRole('button', { name: 'Save new version', exact: true })
        .click();
    await expect(page.locator('.lp-review-doc__version')).toHaveText('v5');
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(page.locator('.lp-history__row')).toHaveCount(5);
    await expect(currentVersion.locator('.lp-history__review')).toHaveCount(2);
});
