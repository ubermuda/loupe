import { expect, test } from '@playwright/test';
import { registerAndVerify } from '../helpers';
import { suppressToolbar, suppressWidget } from '../fixtures';

test.use({ storageState: { cookies: [], origins: [] } });

/**
 * The create-project form lives behind a disclosure, so opening it is the moment
 * the reviewer means to start typing. Focus follows the disclosure rather than
 * the page, which is opt-in per field — the same controller collapses read-only
 * rows elsewhere and must not take the caret there.
 */
test('opening the new-project disclosure puts the caret in the name field', async ({
    page,
    request,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await registerAndVerify(page, request, {
        email: `e2e+new-project+${Date.now()}@example.com`,
        password: 'E2eNewProject1!',
    });

    await page.goto('/projects');
    const headerToggle = page
        .locator('.lp-page-header')
        .getByRole('button', { name: /new project/i });
    const tileToggle = page.locator('.lp-project-new__toggle');
    const panel = page.locator('#new-project-panel');
    await expect(headerToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(tileToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(headerToggle).toHaveAttribute(
        'aria-controls',
        'new-project-panel',
    );
    await expect(tileToggle).toHaveAttribute(
        'aria-controls',
        'new-project-panel',
    );
    await headerToggle.click();

    await expect(page.getByLabel('Project name')).toBeFocused();
    await expect(headerToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(tileToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(panel).toBeVisible();
    await tileToggle.click();
    await expect(panel).toBeHidden();
    await expect(headerToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(tileToggle).toHaveAttribute('aria-expanded', 'false');
    await tileToggle.click();
    await expect(panel).toBeVisible();
    await expect(headerToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(tileToggle).toHaveAttribute('aria-expanded', 'true');
});

test('project descriptions persist on tiles and can be edited or cleared', async ({
    page,
    request,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await registerAndVerify(page, request, {
        email: `e2e+project-description+${Date.now()}@example.com`,
        password: 'E2eProjectDescription1!',
    });
    await page.goto('/projects');
    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: /new project/i })
        .click();
    const name = `Purpose ${'x'.repeat(90)}`;
    const description = `Review the portal.\n${'y'.repeat(400)}`;
    await page.getByLabel('Project name', { exact: true }).fill(name);
    await page
        .getByLabel('Description (optional)', { exact: true })
        .fill(description);
    await page
        .getByRole('button', { name: 'Add project', exact: true })
        .click();
    const tile = page.locator('[data-project-id]').filter({ hasText: name });
    await expect(tile.locator('.lp-project-row__description')).toHaveText(
        description,
    );
    for (const width of [1440, 1150, 950, 780, 390]) {
        await page.setViewportSize({ width, height: 1000 });
        await expect
            .poll(() =>
                tile.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            )
            .toBeLessThanOrEqual(1);
        await expect
            .poll(() =>
                page.evaluate(() => document.documentElement.scrollWidth),
            )
            .toBeLessThanOrEqual(width);
        await tile.scrollIntoViewIfNeeded();
        await page.screenshot({
            path: `/tmp/loupe-project-description-${width}.png`,
            animations: 'disabled',
        });
    }
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await expect
        .poll(() =>
            tile.evaluate(
                (element) => element.scrollWidth - element.clientWidth,
            ),
        )
        .toBeLessThanOrEqual(1);
    await tile.getByRole('link', { name: `Edit ${name}`, exact: true }).click();
    await expect(
        page.getByLabel('Description (optional)', { exact: true }),
    ).toHaveValue(description);
    await page
        .getByLabel('Description (optional)', { exact: true })
        .fill('Updated purpose');
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(tile.locator('.lp-project-row__description')).toHaveText(
        'Updated purpose',
    );
    await tile.getByRole('link', { name: `Edit ${name}`, exact: true }).click();
    await page.getByLabel('Description (optional)', { exact: true }).fill('');
    await page
        .getByRole('button', { name: 'Save changes', exact: true })
        .click();
    await expect(tile).toBeVisible();
    await expect(tile.locator('.lp-project-row__description')).toHaveCount(0);
});
