import { test, expect } from '@playwright/test';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

/**
 * The same table drives DisplayNameDeriverTest, which pins the PHP rules used
 * by social login and `app:admin:create`. The two implementations are separate
 * code; this spec is what stops them drifting apart. Add a row here and add it
 * there too.
 */
const DERIVATIONS: Array<[string, string]> = [
    ['jane.doe@example.com', 'Jane Doe'],
    ['jean-luc_picard@x.com', 'Jean-Luc Picard'],
    ['jane+loupe@example.com', 'Jane'],
    ['JANE.DOE@EXAMPLE.COM', 'Jane Doe'],
    ['jane@example.com', 'Jane'],
    ['jsmith2@x.com', 'Jsmith2'],
    ['a.b@x.com', 'A B'],
    ['mary-jane.watson@x.com', 'Mary-Jane Watson'],
];

test('the display name is derived from the email as it is typed', async ({
    page,
}) => {
    await page.goto('/register');
    const email = page.getByLabel('Email');
    const displayName = page.getByLabel('Display name');

    // The display-name field is never touched here: Playwright's fill() fires
    // an input event, which marks the field hand-edited and would freeze every
    // row after the first.
    for (const [address, expected] of DERIVATIONS) {
        await email.fill(address);
        await expect(displayName).toHaveValue(expected);
    }
});

test('a hand-typed display name survives further email edits, and clearing it re-arms', async ({
    page,
}) => {
    await page.goto('/register');
    const email = page.getByLabel('Email');
    const displayName = page.getByLabel('Display name');

    await email.fill('jane.doe@example.com');
    await expect(displayName).toHaveValue('Jane Doe');

    await displayName.fill('Geoff');
    await email.fill('someone.else@x.com');
    await expect(displayName).toHaveValue('Geoff');

    await displayName.fill('');
    await email.fill('mary-jane.watson@x.com');
    await expect(displayName).toHaveValue('Mary-Jane Watson');
});

test('a display name typed before the email is never overwritten', async ({
    page,
}) => {
    await page.goto('/register');

    await page.getByLabel('Display name').fill('Riley Chen');
    await page.getByLabel('Email').fill('someone.else@x.com');

    await expect(page.getByLabel('Display name')).toHaveValue('Riley Chen');
});

test('a display name that came back on a rejected submission survives further email edits', async ({
    page,
}) => {
    await page.goto('/register');

    // Password and terms are left empty, so the submission is rejected and
    // re-rendered with the display name still filled — no account is created.
    await page.getByLabel('Email').fill('dirty.rerender@example.com');
    await expect(page.getByLabel('Display name')).toHaveValue('Dirty Rerender');
    await page.getByRole('button', { name: 'Create account' }).click();

    await expect(page.locator('form ul li').first()).toBeVisible();
    await expect(page.getByLabel('Display name')).toHaveValue('Dirty Rerender');

    // Deriving from this address would yield 'Someone Else', so the assertion
    // fails if the re-rendered field is not treated as hand-entered.
    await page.getByLabel('Email').fill('someone.else@example.com');

    await expect(page.getByLabel('Display name')).toHaveValue('Dirty Rerender');
});
