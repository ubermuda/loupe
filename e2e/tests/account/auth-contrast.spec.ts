import { test, expect, type Locator, type Page } from '@playwright/test';
import { submitRedirectingForm } from '../helpers';

test.use({ storageState: { cookies: [], origins: [] } });

async function contrast(button: Locator): Promise<number> {
    return button.evaluate((element) => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const context = canvas.getContext('2d')!;
        const luminance = (color: string): number => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
            const [red, green, blue, alpha] = context.getImageData(
                0,
                0,
                1,
                1,
            ).data;
            if (alpha !== 255)
                throw new Error('This check requires opaque button colors');
            return [red, green, blue]
                .map((value) => value / 255)
                .map((value) =>
                    value <= 0.04045
                        ? value / 12.92
                        : ((value + 0.055) / 1.055) ** 2.4,
                )
                .reduce(
                    (total, value, index) =>
                        total + value * [0.2126, 0.7152, 0.0722][index],
                    0,
                );
        };
        const style = getComputedStyle(element);
        const foreground = luminance(style.color);
        const ancestors: Element[] = [];
        for (
            let ancestor: Element | null = element;
            ancestor;
            ancestor = ancestor.parentElement
        ) {
            ancestors.unshift(ancestor);
        }
        context.fillStyle = 'white';
        context.fillRect(0, 0, 1, 1);
        for (const ancestor of ancestors) {
            context.fillStyle = getComputedStyle(ancestor).backgroundColor;
            context.fillRect(0, 0, 1, 1);
        }
        const [red, green, blue] = context.getImageData(0, 0, 1, 1).data;
        const background = luminance(`rgb(${red}, ${green}, ${blue})`);
        return (
            (Math.max(foreground, background) + 0.05) /
            (Math.min(foreground, background) + 0.05)
        );
    });
}

async function assertControlContrast(
    page: Page,
    button: Locator,
): Promise<void> {
    await expect(button).toBeVisible();
    await expect.poll(() => contrast(button)).toBeGreaterThanOrEqual(4.5);
    const link = page
        .locator('main a[href="/login"], main a[href="/register"]')
        .first();
    await expect(link).toBeVisible();
    await expect.poll(() => contrast(link)).toBeGreaterThanOrEqual(4.5);
    await link.hover();
    await expect.poll(() => contrast(link)).toBeGreaterThanOrEqual(4.5);
    await button.hover();
    await expect(button).toHaveCSS('background-color', 'rgb(196, 220, 50)');
    await expect.poll(() => contrast(button)).toBeGreaterThanOrEqual(4.5);
    await button.focus();
    await page.mouse.move(0, 0);
    await expect(button).toHaveCSS('background-color', 'rgb(212, 233, 76)');
    await expect.poll(() => contrast(button)).toBeGreaterThanOrEqual(4.5);
}

for (const path of ['/login', '/register', '/forgot-password']) {
    test(`primary action text has contrast on ${path}`, async ({ page }) => {
        await page.goto(path);
        await expect(page).toHaveURL(path);
        await assertControlContrast(page, page.locator('.auth-submit'));
    });
}

test('verification resend text has contrast', async ({ page }) => {
    await page.goto('/register');
    await page
        .getByLabel('Email')
        .fill(`e2e-auth-contrast-${crypto.randomUUID()}@example.com`);
    await page.getByLabel('Password').fill('SecurePassword1!');
    await page.getByLabel('I agree to').check();
    await submitRedirectingForm(
        page,
        page.getByRole('button', { name: 'Create account' }),
        '/register',
    );
    await expect(page).toHaveURL('/register/check-email');
    await assertControlContrast(
        page,
        page.getByRole('button', { name: 'Resend verification email' }),
    );
});
