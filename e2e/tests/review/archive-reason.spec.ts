/**
 * Archiving takes a reason, and the documents list shows it.
 *
 * A reason can only be set through MCP, which a browser cannot call, so the
 * seeded document is archived with one by the dev-only /dev/seed/document
 * endpoint. The second document is archived through the real button on the
 * list — the path a person takes, which states no reason — and that row must
 * render nothing in the reason's place rather than an empty label or a dash.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

// Guest by default — make the unauthenticated starting state explicit.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const REASON = 'superseded by the v2 plan';

async function devRegisterAndVerify(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    const response = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Archivist', email, password },
    });
    expect(response.status()).toBe(200);
}

async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // A freshly-registered user owns no projects, so LandingController lands them
    // on the first-run wizard; seeding creates the project it would have made.
    await expect(page).toHaveURL('/welcome');
}

async function seedDocument(
    page: Page,
    title: string,
    archiveReason?: string,
): Promise<{ documentId: string; projectId: string }> {
    const form: Record<string, string> = {
        title,
        markdown: `# ${title}\n\nBody text.`,
    };
    if (archiveReason !== undefined) {
        form.archiveReason = archiveReason;
    }

    const response = await page.request.post('/dev/seed/document', { form });
    expect(response.status()).toBe(201);
    const body = await response.json();
    return {
        documentId: body.documentId as string,
        projectId: body.projectId as string,
    };
}

test('an archived document shows its reason, and one archived from the app shows none', async ({
    page,
}) => {
    const email = `e2e-archive-reason-${RUN}@example.com`;
    const password = 'e2e_password_123';

    await suppressToolbar(page);
    await suppressWidget(page);
    await devRegisterAndVerify(page, email, password);
    await login(page, email, password);

    const explained = await seedDocument(page, 'Superseded Draft', REASON);
    const silent = await seedDocument(page, 'Tidied Away');
    const projectId = explained.projectId;

    // The seeded reason archived the first document, so only the second is on
    // the default list — and it is the one with the archive button to press.
    await page.goto(`/projects/${projectId}/documents`);
    const silentRow = page.locator(`[data-document-id="${silent.documentId}"]`);
    await expect(silentRow).toBeVisible();
    await expect(
        page.locator(`[data-document-id="${explained.documentId}"]`),
    ).toHaveCount(0);

    await silentRow
        .getByRole('button', { name: 'Archive Tidied Away' })
        .click();

    // Archiving drops the row from the default list, which is the signal that
    // the POST landed — asserting the URL alone would pass before it did.
    await expect(silentRow).toHaveCount(0);

    await page.goto(`/projects/${projectId}/documents?archived=1`);

    const explainedRow = page.locator(
        `[data-document-id="${explained.documentId}"]`,
    );
    await expect(explainedRow).toBeVisible();
    await expect(
        explainedRow.locator('.lp-document-row__archive-reason'),
    ).toHaveText(`Reason: ${REASON}`);

    // Both rows are listed, so the absence below is the template's doing rather
    // than a row that never rendered.
    await expect(silentRow).toBeVisible();
    await expect(silentRow.locator('.lp-document-row__archived')).toHaveText(
        'Archived',
    );
    await expect(
        silentRow.locator('.lp-document-row__archive-reason'),
    ).toHaveCount(0);
});

test('restoring a document clears the reason it was archived with', async ({
    page,
}) => {
    const email = `e2e-archive-restore-${RUN}@example.com`;
    const password = 'e2e_password_123';

    await suppressToolbar(page);
    await suppressWidget(page);
    await devRegisterAndVerify(page, email, password);
    await login(page, email, password);

    const { documentId, projectId } = await seedDocument(
        page,
        'Restored Draft',
        REASON,
    );

    await page.goto(`/projects/${projectId}/documents?archived=1`);
    const row = page.locator(`[data-document-id="${documentId}"]`);
    await expect(row.locator('.lp-document-row__archive-reason')).toHaveText(
        `Reason: ${REASON}`,
    );

    await row.getByRole('button', { name: 'Restore Restored Draft' }).click();

    // Restoring keeps the row on this view — the archived filter is still on —
    // so the chip going away is what proves the POST landed.
    await expect(row.locator('.lp-document-row__archived')).toHaveCount(0);
    await expect(row.locator('.lp-document-row__archive-reason')).toHaveCount(
        0,
    );
});
