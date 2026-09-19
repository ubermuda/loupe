import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';
import { coverageScaled } from '../timeouts';

// Guest by default, and self-registering through the dev endpoints — the same
// shape as review-loop.spec.ts. The shared worker fixture expects to land on
// /projects after login, which a freshly-registered user with no project does
// not, so review specs that seed their own documents drive their own user.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();
const PASSWORD = 'e2e_password_123';

async function signedInReviewer(page: Page, slug: string): Promise<void> {
    const email = `e2e-decision-${slug}-${RUN}@example.com`;
    const register = await page.request.post('/dev/register-and-verify', {
        form: {
            username: `dec${slug}${RUN}`.slice(0, 30),
            fullName: 'E2E Decisions',
            email,
            password: PASSWORD,
        },
    });
    expect(register.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // No project yet, so LandingController lands on the first-run wizard;
    // seedDocument below creates the project the wizard would have.
    await expect(page).toHaveURL('/welcome', { timeout: 15000 });
    await suppressToolbar(page);
    await suppressWidget(page);
}

const DECISION_ID = 'rollout-order';
const OPTION_ONE = 'Ship the migration first';
const OPTION_TWO = 'Ship the reader first';
const BELOW_BLOCK_PHRASE = 'a paragraph well below the decision block';

const MARKDOWN = `# Rollout

Some prose before the decision.

<!-- decision: ${DECISION_ID} -->

1. ${OPTION_ONE}
2. ${OPTION_TWO}

<!-- /decision -->

This is ${BELOW_BLOCK_PHRASE} in the document.`;

async function seedDocument(
    page: Page,
    title: string,
): Promise<{ documentId: string; reviewUrl: string }> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title, markdown: MARKDOWN },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as {
        documentId: string;
        projectId: string;
    };

    return {
        documentId: body.documentId,
        reviewUrl: `/projects/${body.projectId}/documents/${body.documentId}/review`,
    };
}

test('choosing an option records the answer and survives a reload', async ({
    page,
}) => {
    await signedInReviewer(page, 'persist');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — persistence',
    );
    await page.goto(reviewUrl);

    const block = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    await expect(block).toBeVisible();

    // The radios are grouping-only inputs inside the document's stored HTML;
    // nothing posts without the controller copying their values across.
    const secondOption = block.locator(
        'input[type="radio"][data-decision-option]',
    );
    await expect(secondOption).toHaveCount(2);
    await secondOption.nth(1).check();
    const unsaved = await page.request.get(`/dev/review/${documentId}/state`);
    expect((await unsaved.json()).decisions[0].selected).toBeNull();
    await block.getByRole('button', { name: 'Save decision' }).click();

    // The status region, not the radio's own checked state. The form posts back
    // to the review URL it was submitted from, so toHaveURL resolves instantly,
    // and the radio reads as checked the moment the browser paints it —
    // whether or not the POST ever landed. Reloading on that signal cancels the
    // request in flight and the answer is silently lost.
    await expect(page.locator('#decision-status')).toHaveText(
        /^Decision saved/,
        {
            timeout: coverageScaled(15000),
        },
    );

    await page.reload();
    const afterReload = page
        .locator(`[data-decision-id="${DECISION_ID}"]`)
        .locator('input[type="radio"][data-decision-option]');
    await expect(afterReload.nth(1)).toBeChecked();
    await expect(afterReload.nth(0)).not.toBeChecked();
});

test('the answer reaches the review payload', async ({ page }) => {
    await signedInReviewer(page, 'payload');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — payload',
    );
    await page.goto(reviewUrl);

    const radios = page
        .locator(`[data-decision-id="${DECISION_ID}"]`)
        .locator('input[type="radio"][data-decision-option]');
    await radios.nth(0).check();
    await page.getByRole('button', { name: 'Save decision' }).click();
    await expect(page.locator('#decision-status')).toHaveText(
        /^Decision saved/,
        {
            timeout: coverageScaled(15000),
        },
    );

    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as {
        decisions: Array<{ id: string; selected: string | null }>;
    };

    const decision = state.decisions.find((d) => d.id === DECISION_ID);
    expect(decision).toBeDefined();
    // What the agent reads back is the option text, not the index — so this
    // asserts the whole path, not just that a row was written.
    expect(decision?.selected).toBe(OPTION_ONE);
});

test('selecting text below the block still anchors where the reviewer put it', async ({
    page,
}) => {
    await signedInReviewer(page, 'anchor');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — anchoring',
    );
    await page.goto(reviewUrl);

    // The radios live inside [data-comment-anchor-target="doc"], whose
    // textContent must stay identical to DocumentVersion::plainText(). If
    // converting the list to radios changed the text, every offset below the
    // block would shift and this comment would anchor to the wrong span.
    await page.evaluate((phrase: string) => {
        const doc = document.querySelector(
            '[data-comment-anchor-target="doc"]',
        );
        if (!doc) {
            throw new Error('review pane not found');
        }
        const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
        let node: Node | null = walker.nextNode();
        while (node) {
            const index = (node.textContent ?? '').indexOf(phrase);
            if (index !== -1) {
                const range = document.createRange();
                range.setStart(node, index);
                range.setEnd(node, index + phrase.length);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
                doc.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));

                return;
            }
            node = walker.nextNode();
        }
        throw new Error(`phrase not found: ${phrase}`);
    }, BELOW_BLOCK_PHRASE);

    // The selection raises a toolbar, not the composer; "Comment" opens the
    // composer. exact:true so it does not also match the sidebar's
    // "Add comment" (untargeted) button.
    await expect(
        page.locator('[data-comment-anchor-target="toolbar"]'),
    ).toBeVisible({ timeout: coverageScaled(5000) });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill('Anchored below.');
    await page.getByRole('button', { name: 'Post' }).click();

    await expect(page.locator('.lp-comment-quote').first()).toContainText(
        BELOW_BLOCK_PHRASE,
        { timeout: coverageScaled(15000) },
    );

    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    const state = (await stateRes.json()) as {
        storedAnchors: Array<{ quote: string }>;
    };
    expect(state.storedAnchors).toHaveLength(1);
    expect(state.storedAnchors[0].quote).toBe(BELOW_BLOCK_PHRASE);
});

const PROMPT = 'Which half ships first?';

const PROMPTED_MARKDOWN = `# Rollout

Some prose before the decision.

<!-- decision: ${DECISION_ID} -->

${PROMPT}

1. ${OPTION_ONE}
2. ${OPTION_TWO}

<!-- /decision -->

${'Filler paragraph.\n\n'.repeat(40)}
This is ${BELOW_BLOCK_PHRASE} in the document.`;

test('the Decisions margin reports the saved answer', async ({ page }) => {
    await signedInReviewer(page, 'summary');
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'Decision — summary', markdown: PROMPTED_MARKDOWN },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as {
        documentId: string;
        projectId: string;
    };
    await page.goto(
        `/projects/${body.projectId}/documents/${body.documentId}/review`,
    );

    const tab = page.getByRole('tab', { name: 'Decisions', exact: true });
    await expect(page.locator('#decision-summary-count')).toHaveText('0/1');

    await tab.click();
    const row = page.locator('#decision-summary-list li');
    await expect(row).toHaveCount(1);
    // The block declared a question, so the row is titled with it rather than
    // falling back to the raw decision id.
    await expect(row.locator('.lp-decision-summary__link')).toHaveText(PROMPT);
    await expect(row).toContainText('Not chosen yet');

    await page
        .locator(`[data-decision-id="${DECISION_ID}"]`)
        .locator('input[type="radio"][data-decision-option]')
        .nth(1)
        .check();

    await page.getByRole('button', { name: 'Save decision' }).click();
    await expect(page.locator('#decision-status')).toHaveText(
        'Decision saved on v1.',
        { timeout: coverageScaled(15000) },
    );
    // Streamed with `update`, so the panel the reviewer opened is still open.
    await expect(page.locator('#decision-summary-count')).toHaveText('1/1');
    await expect(row).toContainText(OPTION_TWO);
    await expect(page.locator('#decision-summary-list')).toBeVisible();
});

test('the Decisions margin scrolls to its question without navigating', async ({
    page,
}) => {
    await signedInReviewer(page, 'sticky');
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'Decision — sticky', markdown: PROMPTED_MARKDOWN },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as {
        documentId: string;
        projectId: string;
    };
    await page.goto(
        `/projects/${body.projectId}/documents/${body.documentId}/review`,
    );

    const reviewUrl = page.url();
    await page.getByRole('tab', { name: 'Decisions', exact: true }).click();
    await page.locator('#decision-summary-list a').click();
    await expect(
        page.locator('[data-decision-id="' + DECISION_ID + '"]'),
    ).toBeInViewport();
    await expect(page).toHaveURL(reviewUrl);
});

const MULTIPLE_ID = 'ship-with';

test('a concurrent answer refuses the save and keeps the local choice', async ({
    page,
    context,
}) => {
    await signedInReviewer(page, 'concurrent');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Concurrent decision',
    );
    await page.goto(reviewUrl);
    const other = await context.newPage();
    await other.goto(reviewUrl);
    await page.getByRole('radio', { name: OPTION_ONE, exact: true }).check();
    await other.getByRole('radio', { name: OPTION_TWO, exact: true }).check();
    await other.getByRole('button', { name: 'Save decision' }).click();
    await expect(other.locator('#decision-status')).toHaveText(
        'Decision saved on v1.',
        { timeout: coverageScaled(15000) },
    );
    await page.getByRole('button', { name: 'Save decision' }).click();
    await expect(page.locator('#decision-status')).toContainText(
        'Another answer is saved.',
        { timeout: coverageScaled(15000) },
    );
    await expect(
        page.getByRole('radio', { name: OPTION_ONE, exact: true }),
    ).toBeChecked();
    const response = await page.request.get(`/dev/review/${documentId}/state`);
    expect((await response.json()).decisions[0].selected).toBe(OPTION_TWO);
    await page.reload();
    await expect(
        page.getByRole('radio', { name: OPTION_TWO, exact: true }),
    ).toBeChecked();
    await other.close();
});

const SHIP_ONE = 'The importer';
const SHIP_TWO = 'The exporter';

const MULTIPLE_MARKDOWN = `# Scope

<!-- decision: ${MULTIPLE_ID} -->

Which of these ship first?

- [ ] ${SHIP_ONE}
- [ ] ${SHIP_TWO}

<!-- /decision -->
`;

type ReportedDecision = {
    type: string;
    selected: string | null;
    selections: Array<{ option: string; index: number | null }>;
};

async function readDecision(
    page: Page,
    documentId: string,
): Promise<ReportedDecision | undefined> {
    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as {
        decisions: Array<ReportedDecision & { id: string }>;
    };

    return state.decisions.find((d) => d.id === MULTIPLE_ID);
}

test('a multi-choice block records several answers and clears one', async ({
    page,
}) => {
    await signedInReviewer(page, 'multi');
    const response = await page.request.post('/dev/seed/document', {
        form: { title: 'Decision — multi', markdown: MULTIPLE_MARKDOWN },
    });
    expect(response.status()).toBe(201);
    const body = (await response.json()) as {
        documentId: string;
        projectId: string;
    };
    await page.goto(
        `/projects/${body.projectId}/documents/${body.documentId}/review`,
    );

    const boxes = page
        .locator(`[data-decision-id="${MULTIPLE_ID}"]`)
        .locator('input[type="checkbox"][data-decision-option]');
    await expect(boxes).toHaveCount(2);

    // Polled against what is stored, never against the box's own checked
    // state: the browser paints a tick whether or not the POST landed, and the
    // status region already reads "saved" from the answer before this one.
    await boxes.nth(0).check();
    await boxes.nth(1).check();
    expect((await readDecision(page, body.documentId))?.selections).toEqual([]);
    await page.getByRole('button', { name: 'Save decision' }).click();
    await expect
        .poll(
            async () =>
                (await readDecision(page, body.documentId))?.selections.length,
            { timeout: coverageScaled(15000) },
        )
        .toBe(2);

    let decision = await readDecision(page, body.documentId);
    expect(decision?.type).toBe('multiple');
    expect(decision?.selected).toBeNull();
    expect(decision?.selections.map((s) => s.option)).toEqual([
        SHIP_ONE,
        SHIP_TWO,
    ]);

    await boxes.nth(0).uncheck();
    await page.getByRole('button', { name: 'Save decision' }).click();
    await expect
        .poll(
            async () =>
                (await readDecision(page, body.documentId))?.selections.length,
            { timeout: coverageScaled(15000) },
        )
        .toBe(1);

    decision = await readDecision(page, body.documentId);
    expect(decision?.selections.map((s) => s.option)).toEqual([SHIP_TWO]);

    await page.reload();
    const afterReload = page
        .locator(`[data-decision-id="${MULTIPLE_ID}"]`)
        .locator('input[type="checkbox"][data-decision-option]');
    await expect(afterReload.nth(0)).not.toBeChecked();
    await expect(afterReload.nth(1)).toBeChecked();
});
