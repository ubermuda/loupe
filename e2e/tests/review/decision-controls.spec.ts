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

// Armed before the action, because the status line already reads "Saved."
// from any earlier save on the page and so cannot tell two saves apart.
async function saving(page: Page, action: () => Promise<void>): Promise<void> {
    const response = page.waitForResponse(
        (each) =>
            each.url().endsWith('/decisions/answer') &&
            each.request().method() === 'POST',
        { timeout: coverageScaled(15000) },
    );
    await action();
    expect((await response).status()).toBe(200);
}

async function seedDocument(
    page: Page,
    title: string,
    markdown: string = MARKDOWN,
): Promise<{ documentId: string; reviewUrl: string }> {
    const response = await page.request.post('/dev/seed/document', {
        form: { title, markdown },
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
    const { reviewUrl } = await seedDocument(page, 'Decision — persistence');
    await page.goto(reviewUrl);

    const block = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    await expect(block).toBeVisible();

    const options = block.locator('input[type="radio"][data-decision-option]');
    await expect(options).toHaveCount(2);
    // The radio reads as checked the moment the browser paints it, whether or
    // not the POST landed. Reloading before the answer cancels the save.
    await saving(page, () => options.nth(1).check());
    await expect(page.locator('#decision-status')).toHaveText('Saved.');

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
    await saving(page, () => radios.nth(0).check());

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

async function selectPhrase(page: Page, phrase: string): Promise<void> {
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
    }, phrase);
}

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
    await selectPhrase(page, BELOW_BLOCK_PHRASE);

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

test('the Decisions panel reports the saved answer', async ({ page }) => {
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

    await expect(page.locator('#decision-summary-count')).toHaveText(
        '0 of 1 answered',
    );
    const row = page.locator('#decision-summary-list li');
    await expect(row).toHaveCount(1);
    // The block declared a question, so the row is titled with it rather than
    // falling back to the raw decision id.
    await expect(row.locator('.lp-decision-summary__link')).toHaveText(PROMPT);
    await expect(row).toContainText('Not chosen yet');

    await saving(page, () =>
        page
            .locator(`[data-decision-id="${DECISION_ID}"]`)
            .locator('input[type="radio"][data-decision-option]')
            .nth(1)
            .check(),
    );
    await expect(page.locator('#decision-status')).toHaveText('Saved.');
    // Streamed with `update`, so the panel the reviewer opened is still open.
    await expect(page.locator('#decision-summary-count')).toHaveText(
        '1 of 1 answered',
    );
    await expect(row).toContainText(OPTION_TWO);
    await expect(page.locator('#decision-summary-list')).toBeVisible();
});

const SECOND_ID = 'backfill-window';
const SECOND_PROMPT = 'When does the backfill run?';

test('the Decisions panel is open by default and lists each answer', async ({
    page,
}) => {
    await signedInReviewer(page, 'panel-rows');
    const { reviewUrl } = await seedDocument(
        page,
        'Decision — panel rows',
        `# Rollout

<!-- decision: ${DECISION_ID} -->

${PROMPT}

1. ${OPTION_ONE}
2. ${OPTION_TWO}

<!-- /decision -->

<!-- decision: ${SECOND_ID} -->

${SECOND_PROMPT}

1. Overnight
2. At the weekend

<!-- /decision -->`,
    );
    await page.goto(reviewUrl);

    await expect(
        page.getByRole('button', { name: 'Decisions', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('#review-panel-decisions')).toBeVisible();
    await expect(page.locator('#review-panel-comments')).toBeHidden();
    await expect(page.locator('#review-panel-outline')).toBeHidden();
    await expect(page.locator('#decision-summary-count')).toHaveText(
        '0 of 2 answered',
    );

    await saving(page, () =>
        page.getByRole('radio', { name: OPTION_ONE, exact: true }).check(),
    );
    await saving(page, () =>
        page
            .locator(`[data-decision-id="${SECOND_ID}"]`)
            .getByRole('textbox', { name: 'Note', exact: true })
            .fill(NOTE),
    );

    // Read after a reload, so the rows are the server's and not the stream's.
    await page.reload();
    await expect(page.locator('#decision-summary-count')).toHaveText(
        '2 of 2 answered',
    );
    const rows = page.locator('#decision-summary-list li');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0).locator('.lp-decision-summary__tag')).toHaveText(
        'D1',
    );
    await expect(rows.nth(0).locator('.lp-decision-summary__link')).toHaveText(
        PROMPT,
    );
    await expect(
        rows.nth(0).locator('.lp-decision-summary__answer'),
    ).toHaveText(OPTION_ONE);
    await expect(rows.nth(1).locator('.lp-decision-summary__tag')).toHaveText(
        'D2',
    );
    await expect(rows.nth(1).locator('.lp-decision-summary__link')).toHaveText(
        SECOND_PROMPT,
    );
    await expect(rows.nth(1).locator('.lp-decision-summary__note')).toHaveText(
        `Note: ${NOTE}`,
    );
    await expect(
        rows.nth(1).locator('.lp-decision-summary__answer'),
    ).toHaveCount(0);
    await expect(rows.nth(1)).not.toContainText('Not chosen yet');
});

test('the Decisions panel scrolls to its question without navigating', async ({
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
    await page.locator('#decision-summary-list a').click();
    await expect(
        page.locator('[data-decision-id="' + DECISION_ID + '"]'),
    ).toBeInViewport();
    await expect(page).toHaveURL(reviewUrl);
});

const MULTIPLE_ID = 'ship-with';

test('the last write wins', async ({ page, context }) => {
    await signedInReviewer(page, 'concurrent');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Concurrent decision',
    );
    await page.goto(reviewUrl);
    const other = await context.newPage();
    await other.goto(reviewUrl);

    await saving(page, () =>
        page.getByRole('radio', { name: OPTION_ONE, exact: true }).check(),
    );
    // The second tab still shows the page from before the first answer, and
    // its save is accepted rather than refused.
    await saving(other, () =>
        other.getByRole('radio', { name: OPTION_TWO, exact: true }).check(),
    );

    const response = await page.request.get(`/dev/review/${documentId}/state`);
    expect((await response.json()).decisions[0].selected).toBe(OPTION_TWO);
    await page.reload();
    await expect(
        page.getByRole('radio', { name: OPTION_TWO, exact: true }),
    ).toBeChecked();
    await expect(
        page.getByRole('radio', { name: OPTION_ONE, exact: true }),
    ).not.toBeChecked();
    await other.close();
});

type NotedDecision = {
    id: string;
    selected: string | null;
    note: string | null;
    updated_at: string | null;
};

async function readNoted(
    page: Page,
    documentId: string,
): Promise<NotedDecision | undefined> {
    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    expect(stateRes.status()).toBe(200);
    const state = (await stateRes.json()) as { decisions: NotedDecision[] };

    return state.decisions.find((d) => d.id === DECISION_ID);
}

const NOTE = 'Only if the backfill finishes first.';

test('a decision save and a comment posted at once both land', async ({
    page,
}) => {
    await signedInReviewer(page, 'with-comment');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — with a comment',
    );
    await page.goto(reviewUrl);

    await selectPhrase(page, BELOW_BLOCK_PHRASE);
    await expect(
        page.locator('[data-comment-anchor-target="toolbar"]'),
    ).toBeVisible({ timeout: coverageScaled(5000) });
    await page.getByRole('button', { name: 'Comment', exact: true }).click();
    await page
        .locator('[data-comment-anchor-target="composerBody"]')
        .fill('Posted during a save.');

    // Post goes out while the save is in flight. A form on the Drive
    // navigator stops the submission before it, so one of the two was lost.
    const block = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    await saving(page, async () => {
        await block
            .locator('input[type="radio"][data-decision-option]')
            .nth(0)
            .check();
        await page.getByRole('button', { name: 'Post' }).click();
    });
    await expect(page.locator('#decision-status')).toHaveText('Saved.');
    await expect(page.locator('.lp-comment-quote').first()).toContainText(
        BELOW_BLOCK_PHRASE,
        { timeout: coverageScaled(15000) },
    );

    const stateRes = await page.request.get(`/dev/review/${documentId}/state`);
    const state = (await stateRes.json()) as {
        storedAnchors: Array<{ quote: string }>;
        decisions: NotedDecision[];
    };
    expect(state.storedAnchors).toHaveLength(1);
    expect(state.decisions.find((d) => d.id === DECISION_ID)?.selected).toBe(
        OPTION_ONE,
    );
});

test('a note saves after typing stops and reaches the review payload', async ({
    page,
}) => {
    await signedInReviewer(page, 'note');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — note',
    );
    await page.goto(reviewUrl);

    const block = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    const note = block.getByRole('textbox', { name: 'Note', exact: true });
    // The field keeps focus, so only the typing delay can send this save.
    await saving(page, () => note.fill(NOTE));

    const decision = await readNoted(page, documentId);
    expect(decision?.selected).toBeNull();
    expect(decision?.note).toBe(NOTE);
    expect(decision?.updated_at).not.toBeNull();

    await page.reload();
    await expect(
        page
            .locator(`[data-decision-id="${DECISION_ID}"]`)
            .getByRole('textbox', { name: 'Note', exact: true }),
    ).toHaveValue(NOTE);
});

test('Clear removes the pick and keeps the note', async ({ page }) => {
    await signedInReviewer(page, 'clear');
    const { documentId, reviewUrl } = await seedDocument(
        page,
        'Decision — clear',
    );
    await page.goto(reviewUrl);

    const block = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    const note = block.getByRole('textbox', { name: 'Note', exact: true });
    await saving(page, () =>
        block.getByRole('radio', { name: OPTION_ONE, exact: true }).check(),
    );
    await saving(page, () => note.fill(NOTE));
    expect((await readNoted(page, documentId))?.note).toBe(NOTE);

    await saving(page, () =>
        block.getByRole('button', { name: 'Clear', exact: true }).click(),
    );
    await expect(page.locator('#decision-status')).toHaveText('Cleared.');
    await expect(
        block.locator('input[data-decision-option]:checked'),
    ).toHaveCount(0);
    await expect(note).toHaveValue(NOTE);

    const decision = await readNoted(page, documentId);
    expect(decision?.selected).toBeNull();
    expect(decision?.note).toBe(NOTE);

    await page.reload();
    const reloaded = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    await expect(
        reloaded.locator('input[data-decision-option]:checked'),
    ).toHaveCount(0);
    await expect(
        reloaded.getByRole('textbox', { name: 'Note', exact: true }),
    ).toHaveValue(NOTE);
});

const RECOMMENDED_MARKDOWN = `# Rollout

<!-- decision: ${DECISION_ID} -->

1. ${OPTION_ONE} (recommended: high)
2. ${OPTION_TWO}

<!-- /decision -->
`;

test('a recommended marker shows a badge on its option', async ({ page }) => {
    await signedInReviewer(page, 'badge');
    const { reviewUrl } = await seedDocument(
        page,
        'Decision — recommended',
        RECOMMENDED_MARKDOWN,
    );
    await page.goto(reviewUrl);

    const block = page.locator(`[data-decision-id="${DECISION_ID}"]`);
    const badges = block.locator('.lp-decision__badge');
    await expect(badges).toHaveCount(1);
    await expect(badges).toHaveAttribute(
        'aria-label',
        'Recommended, high confidence',
    );
    await expect(
        block
            .locator('.lp-decision__option')
            .nth(0)
            .locator('.lp-decision__badge'),
    ).toHaveCount(1);
    // The badge sits beside the label, so the option keeps its plain name.
    await expect(
        block.getByRole('radio', { name: OPTION_ONE, exact: true }),
    ).toBeVisible();
    await expect(block).not.toContainText('recommended:');
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
    // state: the browser paints a tick whether or not the POST landed. Each
    // tick sends its own save, queued behind the one in flight.
    await boxes.nth(0).check();
    await boxes.nth(1).check();
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

test('the Decisions button stays in the toolbar when it cannot be opened', async ({
    page,
}) => {
    await signedInReviewer(page, 'disabled-tab');
    const plain = await page.request.post('/dev/seed/document', {
        form: {
            title: `No decisions ${RUN}`,
            markdown: '# Plain\n\nThis document asks nothing.',
            revisions: JSON.stringify([
                '# Plain\n\nThis document asks little.',
            ]),
        },
    });
    expect(plain.status()).toBe(201);
    const { projectId, documentId } = await plain.json();
    const reviewUrl = `/projects/${projectId}/documents/${documentId}/review`;
    const button = page.getByRole('button', {
        name: 'Decisions',
        exact: true,
    });
    const panel = page.locator('#review-panel-decisions');

    // A document that asks nothing still shows the button, and says why it is shut.
    await page.goto(reviewUrl);
    await expect(button).toHaveAttribute('aria-disabled', 'true');
    await expect(button).toHaveAttribute('aria-pressed', 'false');
    await expect(button).toHaveAttribute(
        'title',
        'This document has no decisions to answer.',
    );
    await expect(panel).toBeHidden();

    // Forced, because Playwright reads aria-disabled as not enabled and would
    // otherwise wait for it. The controller still has to refuse the click.
    await button.click({ force: true });
    await expect(button).toHaveAttribute('aria-pressed', 'false');
    await expect(panel).toBeHidden();

    // A comparison cannot answer a decision, so the button is shut there too.
    await page.goto(`${reviewUrl}/diff/1/2?view=rendered`);
    await expect(button).toHaveAttribute('aria-disabled', 'true');
    await expect(button).toHaveAttribute(
        'title',
        'A comparison cannot answer decisions. Open the Document view to answer them.',
    );
    await expect(panel).toBeHidden();
});
