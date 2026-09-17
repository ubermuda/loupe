import { expect } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-project-switcher@example.com',
    password: 'e2e_password_123',
});

test('resizing an open mobile sidebar releases the page at the desktop breakpoint', async ({
    page,
}) => {
    await page.setViewportSize({ width: 780, height: 844 });
    await page.goto('/projects');
    await page
        .getByRole('button', { name: 'Open navigation', exact: true })
        .click();
    const content = page.locator('[data-drawer-target="content"]');
    await expect(content).toHaveJSProperty('inert', true);
    await page.setViewportSize({ width: 950, height: 844 });
    await expect(content).toHaveJSProperty('inert', false);
    await expect(page.locator('#lp-sidebar')).not.toHaveClass(
        /lp-sidebar--open/,
    );
    await expect(page.locator('.lp-sidebar__brand')).toBeFocused();
    await page
        .locator('.lp-page-header')
        .getByRole('button', { name: 'New project', exact: true })
        .click();
    await expect(
        page.getByLabel('Project name', { exact: true }),
    ).toBeFocused();
    await page.locator('.lp-sidebar__brand').focus();
    await page.setViewportSize({ width: 780, height: 844 });
    await expect(
        page.getByRole('button', { name: 'Open navigation', exact: true }),
    ).toBeFocused();
    await expect(content).toHaveJSProperty('inert', false);
});

test('project switching marks context, clears search, and returns keyboard focus', async ({
    page,
}) => {
    const names = [
        `Switcher ${Date.now()} ${'x'.repeat(65)}`,
        `Other ${Date.now()}`,
    ];
    const urls: string[] = [];
    for (const name of names) {
        await page.goto('/projects');
        await page
            .locator('.lp-page-header')
            .getByRole('button', { name: 'New project', exact: true })
            .click();
        await page.getByLabel('Project name', { exact: true }).fill(name);
        await page
            .getByRole('button', { name: 'Add project', exact: true })
            .click();
        await expect(page.locator('[data-workshop]')).toBeVisible();
        await expect(page.locator('.lp-sidebar__switcher-name')).toHaveText(
            name,
        );
        urls.push(new URL(page.url()).pathname);
    }

    const search = page.getByRole('dialog', {
        name: 'Search project',
        exact: true,
    });
    await page.keyboard.press('Control+k');
    await expect(search).toBeVisible();
    await search.getByRole('searchbox').fill('Previous project query');
    await page.keyboard.press('Escape');
    await expect(search).toBeHidden();

    const trigger = page.getByRole('button', {
        name: 'Switch project',
        exact: true,
    });
    const panel = page.locator('#project-switcher-panel');
    await trigger.click();
    await expect(trigger).toHaveAttribute(
        'aria-controls',
        'project-switcher-panel',
    );
    const selected = panel.locator('[aria-current="true"]');
    await expect(selected).toHaveCount(1);
    await expect(selected).toHaveAttribute('href', urls[1]);
    await expect(selected.locator('.lp-switcher__item-check')).toBeVisible();
    await page.keyboard.press('Tab');
    await expect(selected).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(trigger).toBeFocused();
    await trigger.press('Enter');
    await panel.locator(`a[href="${urls[0]}"]`).click();
    await expect(page.locator('.lp-sidebar__switcher-name')).toHaveText(
        names[0],
    );
    await expect(page).toHaveURL(new RegExp(`${urls[0]}$`));
    await expect(panel).toBeHidden();
    await expect(selected).toHaveAttribute('href', urls[0]);
    await page.keyboard.press('Control+k');
    await expect(search).toBeVisible();
    await expect(search.getByRole('searchbox')).toHaveValue('');
    await page.keyboard.press('Escape');
    await expect(search).toBeHidden();

    await page.goBack();
    await expect(page.locator('.lp-sidebar__switcher-name')).toHaveText(
        names[1],
    );
    await expect(panel).toBeHidden();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');

    for (const width of [1440, 1150, 950]) {
        await page.setViewportSize({ width, height: 1000 });
        await trigger.click();
        await expect(
            selected.locator('.lp-switcher__item-check'),
        ).toBeVisible();
        await expect
            .poll(() =>
                panel.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            )
            .toBeLessThanOrEqual(1);
        await panel.screenshot({
            path: `/tmp/loupe-project-switcher-${width}.png`,
            animations: 'disabled',
        });
        await page.keyboard.press('Escape');
    }

    const navigation = page.getByRole('button', {
        name: 'Open navigation',
        exact: true,
    });
    const sidebar = page.locator('#lp-sidebar');
    for (const width of [780, 390]) {
        await page.setViewportSize({ width, height: 844 });
        await navigation.click();
        await trigger.click();
        await page.keyboard.press('Tab');
        await expect(selected).toBeFocused();
        await expect
            .poll(() =>
                panel.evaluate(
                    (element) => element.scrollWidth - element.clientWidth,
                ),
            )
            .toBeLessThanOrEqual(1);
        await panel.screenshot({
            path: `/tmp/loupe-project-switcher-${width}.png`,
            animations: 'disabled',
        });
        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
        await expect(sidebar).toHaveClass(/lp-sidebar--open/);
        await expect(trigger).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(sidebar).not.toHaveClass(/lp-sidebar--open/);
        await expect(navigation).toBeFocused();
    }
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await navigation.click();
    await trigger.click();
    await expect(selected.locator('.lp-switcher__item-check')).toBeVisible();
    await expect
        .poll(() =>
            panel.evaluate(
                (element) => element.scrollWidth - element.clientWidth,
            ),
        )
        .toBeLessThanOrEqual(1);
    await panel.screenshot({
        path: '/tmp/loupe-project-switcher-text200.png',
        animations: 'disabled',
    });
});
