import { test, expect } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

/**
 * The route from the review page's Versions tab to the history page, and from
 * the history page's picker to the diff of two versions that do not follow one
 * another.
 *
 * The tab bar is display:none below lg and the panel opens through a Stimulus
 * controller, so neither the link nor the picker is reachable from PHPUnit.
 */
// Guest by default, and self-registering through the dev endpoints — the same
// shape as the other review specs, which seed their own document.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'e2e_password_123';

test('the versions tab leads to the history, which compares two distant versions', async ({
    page,
}) => {
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

    // The panel is hidden until the tab opens it, so the link inside it is not
    // in the accessibility tree before the click.
    await page.locator('[data-metadata-tabs-panel-param="versions"]').click();
    const panel = page.locator('[data-panel="versions"]');
    await expect(panel).toBeVisible();

    // The trimmed panel: the version on screen, and nothing else.
    await expect(panel.locator('.lp-version-entry')).toHaveCount(1);
    await expect(panel.locator('.lp-version-entry')).toHaveAttribute(
        'data-version-number',
        '4',
    );

    await panel.getByRole('link', { name: 'See all 4 versions' }).click();
    await expect(page).toHaveURL(
        `/projects/${projectId}/documents/${documentId}/review/history`,
    );
    await expect(
        page.getByRole('heading', { name: 'Version history' }),
    ).toBeVisible();
    await expect(page.locator('.lp-history__row')).toHaveCount(4);

    // v1 against v4: a pair the per-version compare controls never offer.
    await page.selectOption('#history-compare-from', '1');
    await page.selectOption('#history-compare-to', '4');
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
});
