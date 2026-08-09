import { expect, test } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

test.use({ storageState: { cookies: [], origins: [] } });

/**
 * The four controls must tab in the order they are read. The forgot-password
 * link used to sit between the label and the password box in the DOM, so
 * tabbing out of the email field landed on it rather than on the password.
 */
test('the login form tabs email, password, stay signed in, sign in', async ({
    page,
}) => {
    await suppressToolbar(page);
    await suppressWidget(page);
    await page.goto('/login');

    await page.getByLabel('Email').focus();

    const order: string[] = [];
    for (let step = 0; step < 3; step += 1) {
        await page.keyboard.press('Tab');
        order.push(
            await page.evaluate(() => {
                const el = document.activeElement as HTMLElement | null;
                return el?.getAttribute('name') ?? el?.tagName ?? '';
            }),
        );
    }

    expect(order).toEqual(['password', '_remember_me', 'BUTTON']);
});
