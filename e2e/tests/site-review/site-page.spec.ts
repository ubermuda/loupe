/**
 * Full-loop e2e: comments sent from the widget land on the site page with
 * Pending badges; the human resolves and reopens them there.
 *
 * The harness site (`e2e-harness`) persists across runs and accumulates one
 * submitted review per run, so the comment body carries a per-run marker to
 * keep the locator unambiguous.
 */

import { test, expect } from '@playwright/test';
import { suppressToolbar } from '../fixtures';

// The test starts as a guest (widget flow) and logs in itself mid-test.
test.use({ storageState: { cookies: [], origins: [] } });

const E2E_EMAIL = 'e2e-site-page@example.com';
const E2E_PASSWORD = 'E2eSitePage1!';
const COMMENT_BODY = `Please fix the header (run ${Date.now()})`;

test('a sent review is resolvable on the site page', async ({ page }) => {
    await suppressToolbar(page);

    // Seed the user (idempotent — re-registering is handled by the dev endpoint).
    const registerResponse = await page.request.post(
        '/dev/register-and-verify',
        {
            form: {
                fullName: 'E2E Site Page',
                email: E2E_EMAIL,
                password: E2E_PASSWORD,
            },
        },
    );
    expect(registerResponse.status()).toBe(200);

    // Annotate + send via the widget on the harness page. The harness resets
    // the draft on load, so the review contains exactly this one comment.
    await page.goto(
        `/dev/site-review-harness?email=${encodeURIComponent(E2E_EMAIL)}`,
    );
    await page.getByRole('button', { name: 'Review' }).click();
    await page
        .locator('#lp-panel')
        .getByRole('button', { name: 'Add note' })
        .click();
    await page.getByPlaceholder(/Describe the issue/).fill(COMMENT_BODY);
    await page.getByRole('button', { name: 'Save' }).click();
    // Wait for the save POST to land before sending.
    await expect(page.locator('#lp-head-count')).toHaveText('1');
    await page.getByRole('button', { name: 'Send' }).click();
    await expect(page.getByText('Review sent')).toBeVisible();

    // Log in and open the harness site's page.
    await page.goto('/login');
    await page.getByLabel('Email').fill(E2E_EMAIL);
    await page.getByLabel('Password').fill(E2E_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // The harness created the `e2e-harness` project above, so this owner has
    // exactly one project and HomeController lands them on its documents
    // dashboard. Capture the project id from that URL to reach the site-review
    // page (which has no nav link yet — that arrives in a later Loop PR).
    await expect(page).toHaveURL(/\/projects\/[0-9a-f-]+\/documents$/);
    const projectId = /\/projects\/([0-9a-f-]+)\/documents$/.exec(
        page.url(),
    )?.[1];
    expect(projectId).toBeTruthy();
    await page.goto(`/projects/${projectId}/site-review`);

    // The submitted review shows the comment as Pending.
    const comment = page.locator('[data-comment-id]', {
        hasText: COMMENT_BODY,
    });
    await expect(comment).toHaveAttribute('data-comment-status', 'pending');
    await expect(comment.getByText('Pending')).toBeVisible();

    // Resolve flips the status. The form POST redirects back to this same
    // page, so the attribute change is the post-submit signal to wait on.
    await comment.getByRole('button', { name: 'Resolve' }).click();
    await expect(comment).toHaveAttribute('data-comment-status', 'resolved');
    await expect(comment.getByText('Resolved')).toBeVisible();

    // Reopen flips it back.
    await comment.getByRole('button', { name: 'Reopen' }).click();
    await expect(comment).toHaveAttribute('data-comment-status', 'pending');
    await expect(comment.getByText('Pending')).toBeVisible();
});
