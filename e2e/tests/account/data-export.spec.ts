import { expect } from '@playwright/test';
import { createTest } from '../fixtures';
import { getEmailWithSubject, extractLink } from '../helpers';

// Dedicated user: the worker processes this user's export asynchronously, and
// no other spec file should be racing an export request for the same inbox.
const test = createTest({
    email: 'e2e-data-export@example.com',
    password: 'e2e_password_123!',
    name: 'Data Export User',
});

test('requesting a data export emails a working download link', async ({
    page,
    request,
}) => {
    // Requires the dev worker container to be running (`docker compose up -d worker`
    // or `just worker`) — it consumes the async transport that generates the export.
    test.slow();

    await page.goto('/account');
    await expect(page.locator('[data-testid="export-section"]')).toBeVisible();

    await page
        .locator('[data-testid="export-section"]')
        .getByRole('button', { name: 'Request export' })
        .click();
    await expect(page).toHaveURL('/account');
    await expect(page.locator('.lp-flash--success')).toBeVisible();

    // The fixture's own registration step already sent a "Confirm your
    // account" email to this address, so we must wait for a message matching
    // the export's subject specifically — grabbing whatever's newest at the
    // first poll (getLatestEmailTo) can return that older verification email
    // before the worker has finished generating and mailing the export.
    const received = await getEmailWithSubject(
        request,
        'e2e-data-export@example.com',
        'Your Loupe data export is ready',
    );
    const downloadUrl = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/account\/exports\/[^\s"<]+\/download\?token=[^\s"<]+/,
    );

    const response = await page.request.get(downloadUrl);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('zip');
});
