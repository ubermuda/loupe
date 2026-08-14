import { test, expect } from '@playwright/test';

/**
 * The policy pages are what a visitor agrees to by signing up, so they have to
 * be readable while signed out — and reachable from the footer that claims they
 * are. Both halves have failed silently before: the footer links were `href="#"`
 * placeholders, which look right in a screenshot and go nowhere.
 *
 * Anchored on the policy pages rather than on `/`, which serves the marketing
 * page to guests only where billing is on and otherwise redirects to /login.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const PAGES = [
    { path: '/privacy', heading: 'Privacy Policy' },
    { path: '/terms', heading: 'Terms of Use' },
    { path: '/ai-policy', heading: 'AI Policy' },
];

for (const { path, heading } of PAGES) {
    test(`${path} is readable signed out`, async ({ page }) => {
        await page.goto(path);
        await expect(
            page.getByRole('heading', { name: heading, level: 1 }),
        ).toBeVisible();
    });
}

test('the footer links to every policy, and to nothing dead', async ({
    page,
}) => {
    await page.goto('/privacy');
    const footer = page.locator('.lp-landing-footer__nav');

    for (const { path, heading } of PAGES) {
        await expect(
            footer.getByRole('link', {
                name: heading
                    .replace('Terms of Use', 'Terms')
                    .replace('Privacy Policy', 'Privacy'),
                exact: true,
            }),
        ).toHaveAttribute('href', path);
    }

    await expect(footer.getByRole('link', { name: 'Status' })).toHaveCount(0);
    await expect(footer.locator('a[href="#"]')).toHaveCount(0);
});

test('a policy is reachable from the footer of another', async ({ page }) => {
    await page.goto('/privacy');
    await page
        .locator('.lp-landing-footer__nav')
        .getByRole('link', { name: 'AI Policy', exact: true })
        .click();
    await expect(page).toHaveURL('/ai-policy');
    await expect(
        page.getByRole('heading', { name: 'AI Policy', level: 1 }),
    ).toBeVisible();
});

test('the policies cross-link to each other in the prose', async ({ page }) => {
    await page.goto('/privacy');
    await page
        .locator('.lp-legal__body')
        .getByRole('link', { name: 'AI Policy' })
        .click();
    await expect(page).toHaveURL('/ai-policy');
});
