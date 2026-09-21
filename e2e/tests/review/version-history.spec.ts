import { expect } from '@playwright/test';
import { createTest, suppressToolbar, suppressWidget } from '../fixtures';

const TITLE = 'Versioned Plan';
const FULL_NAME = 'E2E History';

// One login for the file, held by the worker fixture the other specs use. Both
// tests below seed their own document, so neither can disturb the other.
const base = createTest({
    email: 'e2e-version-history@example.com',
    password: 'e2e_password_123',
    fullName: FULL_NAME,
});

interface SeededHistory {
    documentId: string;
    reviewUrl: string;
}

const test = base.extend<{ seeded: SeededHistory }>({
    seeded: async ({ page }, use) => {
        await suppressToolbar(page);
        await suppressWidget(page);

        const seeded = await page.request.post('/dev/seed/document', {
            form: {
                title: TITLE,
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

        await use({
            documentId,
            reviewUrl: `/projects/${projectId}/documents/${documentId}/review`,
        });
    },
});

test('the History tab records a verdict and its withdrawal', async ({
    page,
    seeded,
}) => {
    await page.goto(seeded.reviewUrl);
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(page).toHaveURL(`${seeded.reviewUrl}/history`);
    await expect(
        page.locator(
            '.lp-review-workspace-nav .lp-tabs__tab[aria-current="page"]',
        ),
    ).toHaveText('History');
    await expect(
        page.getByRole('heading', { name: TITLE, exact: true }),
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
    await page.keyboard.press('Escape');
    await expect(reviewDialog).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Finish review', exact: true }),
    ).toBeFocused();
    await page
        .getByRole('button', { name: 'Finish review', exact: true })
        .click();
    await expect(
        reviewDialog.getByLabel('Review note', { exact: true }),
    ).toHaveValue('Ready for the rollout.');
    await expect(
        reviewDialog.getByRole('radio', { name: 'Approve', exact: true }),
    ).toBeChecked();
    await reviewDialog.getByRole('button', { name: 'Submit review' }).click();
    // The allowance review-loop gives the same assertion. This one missed its
    // 5 second default once on a loaded machine, at a measured 2.8 seconds.
    await expect(page.locator('.lp-verdict-bar--approved')).toBeVisible({
        timeout: 20000,
    });
    await page
        .locator('.lp-verdict-bar')
        .getByRole('button', { name: 'Undo', exact: true })
        .click();
    await expect(page.locator('.lp-verdict-bar')).toHaveCount(0);

    // A visit rather than a click on the tab, which the undo re-render can
    // swallow. The click above already proved the tab navigates.
    await page.goto(`${seeded.reviewUrl}/history`);
    await expect(
        page.getByRole('heading', { name: 'Version history' }),
    ).toBeVisible();
    await expect(page.locator('.lp-history__row')).toHaveCount(4);
    // Verdicts read as one log under the table, so the row itself reports its
    // discussion instead.
    const log = page.locator('.lp-history-log');
    await expect(log.locator('.lp-history__verdict')).toHaveText([
        'Approved',
        'Verdict withdrawn',
    ]);
    await expect(log).toContainText('Ready for the rollout.');
    await expect(log).toContainText(FULL_NAME);
    await expect(
        page.locator('.lp-history__row[data-version-number="3"]'),
    ).toContainText('No threads');
    await page.reload();
    await expect(log.locator('.lp-history__review')).toHaveCount(2);

    // A new version leaves the verdicts where they were, on v4. The dialog that
    // writes one is the next test's subject, so this one revises over the API.
    const revised = await page.request.post(
        `/dev/review/${seeded.documentId}/revise`,
        {
            form: {
                markdown: '# Plan\n\nThe rollout takes five verified steps.',
                description: 'Add the verification step.',
            },
        },
    );
    expect(revised.status()).toBe(200);
    await page.reload();
    await expect(page.locator('.lp-history__row')).toHaveCount(5);
    await expect(log.locator('.lp-history__review')).toHaveCount(2);
});

test('the History tab compares two distant versions', async ({
    page,
    seeded,
}) => {
    await page.goto(`${seeded.reviewUrl}/history`);
    await expect(
        page.getByRole('heading', { name: 'Version history' }),
    ).toBeVisible();

    // v1 against v4: a pair the per-version compare controls never offer.
    await page.locator('#history-compare-from').selectOption('1');
    await page.locator('#history-compare-to').selectOption('4');
    await page.getByRole('button', { name: 'Compare', exact: true }).click();

    await expect(page).toHaveURL(`${seeded.reviewUrl}/diff/1/4`);
    await expect(page.locator('#diff-from')).toHaveValue('1');
    await expect(page.locator('#diff-to')).toHaveValue('4');
    await expect(
        page.locator('.lp-diff__mark--deleted', { hasText: 'one step' }),
    ).toHaveCount(1);
    await expect(
        page.locator('.lp-diff__mark--inserted', {
            hasText: 'four careful steps',
        }),
    ).toHaveCount(1);

    await page.evaluate(() => {
        performance.clearMarks('review-preview-render');
        document.addEventListener('turbo:render', () => {
            if (document.documentElement.hasAttribute('data-turbo-preview')) {
                performance.mark('review-preview-render');
            }
        });
    });
    // Only the visit below, or the delay also slows the last navigation.
    await page.route(
        `**/documents/${seeded.documentId}/review/history`,
        async (route) => {
            const response = await route.fetch();
            await new Promise((resolve) => setTimeout(resolve, 500));
            await route.fulfill({ response });
        },
        { times: 1 },
    );
    await page.getByRole('link', { name: 'History', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Version history' }),
    ).toBeVisible();
    await expect(page.locator('html')).not.toHaveAttribute(
        'data-turbo-preview',
    );
    await expect(page.locator('html')).not.toHaveAttribute('aria-busy', 'true');
    expect(
        await page.evaluate(
            () => performance.getEntriesByName('review-preview-render').length,
        ),
    ).toBe(0);
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    const reviseDialog = page.getByRole('dialog', { name: 'Revise document' });
    await expect(reviseDialog.getByLabel('Title', { exact: true })).toHaveValue(
        TITLE,
    );
    await reviseDialog
        .getByLabel('Markdown', { exact: true })
        .fill('# Plan\n\nThe rollout takes five verified steps.');
    await reviseDialog
        .getByLabel('Revision note', { exact: true })
        .fill('Add the verification step.');
    await page.keyboard.press('Escape');
    await expect(reviseDialog).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Revise', exact: true }),
    ).toBeFocused();
    await page.getByRole('button', { name: 'Revise', exact: true }).click();
    await expect(
        reviseDialog.getByLabel('Markdown', { exact: true }),
    ).toHaveValue('# Plan\n\nThe rollout takes five verified steps.');
    await expect(
        reviseDialog.getByLabel('Revision note', { exact: true }),
    ).toHaveValue('Add the verification step.');
    await reviseDialog
        .getByRole('button', { name: 'Save new version', exact: true })
        .click();
    await expect(page.locator('.lp-review-doc__version')).toHaveText('v5');
    // A visit rather than a click, which the save re-render can swallow.
    await page.goto(`${seeded.reviewUrl}/history`);
    await expect(page.locator('.lp-history__row')).toHaveCount(5);
});
