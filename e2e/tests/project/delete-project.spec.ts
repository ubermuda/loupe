import { expect } from '@playwright/test';
import { createTest } from '../fixtures';

const test = createTest({
    email: 'e2e-delete-project@example.com',
    password: 'e2e_password_123',
    name: 'Delete Project Tester',
});

test('deleting a project requires typing its exact name', async ({ page }) => {
    const projectName = `doomed-${Date.now()}`;

    // Create the project through the real UI. "New project" is a disclosure
    // toggle (not a link) that reveals the create form.
    await page.goto('/projects');
    await page.getByRole('button', { name: /new project/i }).click();
    await page.getByLabel('Project name').fill(projectName);
    await page.getByRole('button', { name: 'Add project' }).click();

    // The create form redirects back to /projects (same URL) — wait for the
    // new row's edit link instead of the URL, which would resolve immediately.
    // The project name itself is not a link (it overlaps the row's "open
    // documents" link), so the edit link is both the signal and the target.
    const editLink = page.getByRole('link', { name: `Edit ${projectName}` });
    await expect(editLink).toBeVisible();
    await editLink.click();
    await expect(
        page.getByRole('button', { name: /delete this project/i }),
    ).toBeVisible();

    await page.getByRole('button', { name: /delete this project/i }).click();

    const confirmButton = page.getByRole('button', { name: /i understand/i });
    await expect(confirmButton).toBeDisabled();

    const confirmInput = page.getByLabel(/type the project name/i);
    await confirmInput.fill(projectName.slice(0, -1));
    await expect(confirmButton).toBeDisabled();

    await confirmInput.fill(projectName);
    await expect(confirmButton).toBeEnabled();
    await confirmButton.click();

    // Post-submit content signal (the delete redirects to a different URL, but
    // the success flash is the stated acceptance criterion, not just the URL).
    await expect(page).toHaveURL(/\/projects$/);
    await expect(page.locator('.lp-flash')).toContainText('permanently deleted');
    await expect(page.getByText(projectName, { exact: true })).toHaveCount(0);
});
