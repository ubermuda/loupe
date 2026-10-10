/**
 * End-to-end tests for the verdict buttons of the site-review widget.
 *
 * A preview page names its card with `data-context="card:<uuid>"`. The harness
 * page names none, so each test adds the attribute to the harness document on
 * its way in. The card is not real: the review read and the verdict routes are
 * stubbed, which keeps these tests about the widget alone. The server side has
 * its own PHPUnit tests.
 *
 * Each worker owns its own user, as in widget.spec.ts, because the harness
 * clears the comments of the user's project on every load.
 */

import { test, expect, type Page, type Route } from '@playwright/test';
import { signWidgetIn, suppressToolbar } from '../fixtures';

test.use({ storageState: { cookies: [], origins: [] } });
test.describe.configure({ mode: 'parallel' });

const E2E_PASSWORD = 'E2eSiteReview1!';
const CARD = '0197a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const PR_A = '0197a1b2-0000-7000-8000-00000000000a';
const PR_B = '0197a1b2-0000-7000-8000-00000000000b';

const e2eEmail = (): string =>
    `e2e-site-review-verdict-${test.info().parallelIndex}@example.com`;

const harnessUrl = (): string =>
    `/dev/site-review-harness?email=${encodeURIComponent(e2eEmail())}`;

type VerdictAnswer = Record<string, unknown>;

const answer = (overrides: VerdictAnswer = {}): VerdictAnswer => {
    const posts = {
        actions: [
            { code: 'post-review', label: 'Posts your verdict as a review.' },
        ],
    };

    return {
        cardId: CARD,
        connection: { state: 'connected' },
        pullRequests: [
            { id: PR_A, label: 'acme/site#12', ownPullRequest: false },
        ],
        notes: [],
        preview: { approve: posts, 'request-changes': posts, comment: posts },
        latestVerdict: null,
        ...overrides,
    };
};

const json = (route: Route, body: unknown, status = 200): Promise<void> =>
    route.fulfill({
        status,
        contentType: 'application/json',
        body: JSON.stringify(body),
    });

/**
 * Signs this worker's user in, stubs the card's endpoints and loads the
 * harness with the card lock. `verdict` can be a function, so a test changes
 * the answer between two reads. Returns the bodies the widget posts.
 */
const openLockedPage = async (
    page: Page,
    verdict: () => VerdictAnswer,
): Promise<Array<Record<string, unknown>>> => {
    await suppressToolbar(page);
    const email = e2eEmail();
    const registered = await page.request.post('/dev/register-and-verify', {
        form: {
            fullName: `E2E Site Review Verdict ${test.info().parallelIndex}`,
            email,
            password: E2E_PASSWORD,
        },
    });
    expect(registered.status()).toBe(200);

    // The mint needs a session, which the register endpoint does not create.
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(E2E_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).not.toHaveURL(/\/login$/, { timeout: 15_000 });

    const harness = await page.request.get(harnessUrl());
    expect(harness.ok()).toBeTruthy();
    const projectId = /data-project="([^"]+)"/.exec(await harness.text())?.[1];
    expect(projectId).toBeTruthy();
    await signWidgetIn(page, projectId!);

    const posted: Array<Record<string, unknown>> = [];
    await page.route('**/api/site-review/review*', (route) =>
        json(route, {
            comments: [],
            context: { label: '#7 Preview card', url: null },
        }),
    );
    await page.route(/\/api\/board\/cards\/[^/]+\/verdict$/, (route) =>
        json(route, verdict()),
    );
    await page.route(/\/api\/board\/cards\/[^/]+\/verdicts$/, (route) => {
        posted.push(route.request().postDataJSON());

        return json(
            route,
            { verdictId: 'v1', kind: 'approve', noteCount: 0 },
            201,
        );
    });
    await page.route('**/dev/site-review-harness*', async (route) => {
        const response = await route.fetch();
        const body = (await response.text()).replace(
            'data-project=',
            `data-context="card:${CARD}" data-project=`,
        );
        await route.fulfill({ response, body });
    });
    await page.goto(harnessUrl());

    return posted;
};

const panel = (page: Page) => page.locator('#lp-panel');
const verdictButton = (page: Page, name: string) =>
    panel(page).getByRole('button', { name, exact: true });

const openPanel = async (page: Page): Promise<void> => {
    await page.getByRole('button', { name: 'Review' }).click();
    await expect(panel(page)).toBeVisible();
};

test('a locked card with an open pull request offers the three verdicts', async ({
    page,
}) => {
    await openLockedPage(page, () => answer());
    await openPanel(page);

    for (const name of ['Approve', 'Request changes', 'Comment']) {
        await expect(verdictButton(page, name)).toBeVisible();
    }
});

test('a card with no open pull request offers no verdict', async ({ page }) => {
    await openLockedPage(page, () =>
        answer({
            pullRequests: [],
            latestVerdict: {
                id: 'v1',
                kind: 'approve',
                message: '',
                createdAt: '2026-10-08T10:00:00+00:00',
                deliveries: [],
            },
        }),
    );
    await openPanel(page);

    // The last verdict only renders once the read has landed, so the check
    // below cannot pass before the answer arrived.
    await expect(panel(page).getByText('Last verdict: Approve')).toBeVisible();
    await expect(verdictButton(page, 'Approve')).toHaveCount(0);
});

test('an approval sends after the confirm panel and says so', async ({
    page,
}) => {
    const posted = await openLockedPage(page, () => answer());
    await openPanel(page);

    await verdictButton(page, 'Approve').click();
    await expect(
        panel(page).getByText('Approve for #7 Preview card'),
    ).toBeVisible();
    await expect(panel(page).getByText('acme/site#12')).toBeVisible();
    await expect(
        panel(page).getByText('Posts your verdict as a review.'),
    ).toBeVisible();
    expect(posted).toEqual([]);

    await Promise.all([
        page.waitForResponse((response) =>
            response.url().endsWith('/verdicts'),
        ),
        panel(page).getByRole('button', { name: 'Send', exact: true }).click(),
    ]);

    await expect(panel(page).getByText('Sent to the workflow')).toBeVisible();
    expect(posted).toEqual([
        {
            kind: 'approve',
            pullRequestIds: [PR_A],
            message: '',
            submissionId: expect.stringMatching(UUID),
        },
    ]);
});

test('request changes with no open note needs a message and warns about the reviewer’s own pull request', async ({
    page,
}) => {
    const posted = await openLockedPage(page, () =>
        answer({
            pullRequests: [
                { id: PR_A, label: 'acme/site#12', ownPullRequest: true },
            ],
        }),
    );
    await openPanel(page);

    await verdictButton(page, 'Request changes').click();
    const send = panel(page).getByRole('button', { name: 'Send', exact: true });
    await expect(
        panel(page).getByText(/You opened this pull request/),
    ).toBeVisible();
    await expect(send).toBeDisabled();

    await panel(page).getByLabel('Message').fill('The heading is cut off');
    await expect(send).toBeEnabled();
    await Promise.all([
        page.waitForResponse((response) =>
            response.url().endsWith('/verdicts'),
        ),
        send.click(),
    ]);

    expect(posted).toEqual([
        {
            kind: 'request-changes',
            pullRequestIds: [PR_A],
            message: 'The heading is cut off',
            submissionId: expect.stringMatching(UUID),
        },
    ]);
});

test('request changes on a card with an open note sends with no message', async ({
    page,
}) => {
    const posted = await openLockedPage(page, () =>
        answer({
            notes: [
                {
                    id: 'n1',
                    url: 'https://x.test/',
                    body: 'Heading is cut off',
                    anchorCount: 1,
                },
            ],
        }),
    );
    await openPanel(page);

    await verdictButton(page, 'Request changes').click();
    await expect(
        panel(page).getByText('1 note goes with this review'),
    ).toBeVisible();
    await expect(panel(page).getByLabel('Message')).toHaveAttribute(
        'placeholder',
        'Message (optional)',
    );
    const send = panel(page).getByRole('button', { name: 'Send', exact: true });
    await expect(send).toBeEnabled();
    await Promise.all([
        page.waitForResponse((response) =>
            response.url().endsWith('/verdicts'),
        ),
        send.click(),
    ]);

    expect(posted).toEqual([
        {
            kind: 'request-changes',
            pullRequestIds: [PR_A],
            message: '',
            submissionId: expect.stringMatching(UUID),
        },
    ]);
});

test('two pull requests ask the reviewer to tick the ones that get the review', async ({
    page,
}) => {
    const posted = await openLockedPage(page, () =>
        answer({
            pullRequests: [
                { id: PR_A, label: 'acme/site#12', ownPullRequest: false },
                { id: PR_B, label: 'acme/api#40', ownPullRequest: false },
            ],
        }),
    );
    await openPanel(page);

    await verdictButton(page, 'Approve').click();
    const send = panel(page).getByRole('button', { name: 'Send', exact: true });
    await expect(send).toBeDisabled();
    await panel(page).getByLabel('acme/api#40').check();
    await expect(send).toBeEnabled();
    await Promise.all([
        page.waitForResponse((response) =>
            response.url().endsWith('/verdicts'),
        ),
        send.click(),
    ]);

    expect(posted).toEqual([
        {
            kind: 'approve',
            pullRequestIds: [PR_B],
            message: '',
            submissionId: expect.stringMatching(UUID),
        },
    ]);
});

test('connecting GitHub keeps the draft and brings Send back', async ({
    page,
}) => {
    let connection = 'none';
    await openLockedPage(page, () =>
        answer({ connection: { state: connection } }),
    );
    // The pop-up closes itself, as the callback page does after it reports back.
    await page.context().route('**/account/github/connect*', (route) =>
        route.fulfill({
            contentType: 'text/html',
            body: '<script>window.close()</script>',
        }),
    );
    await openPanel(page);

    await verdictButton(page, 'Comment').click();
    await panel(page).getByLabel('Message').fill('Needs a second look');
    const connect = panel(page).getByRole('button', {
        name: 'Connect GitHub to send this review',
    });
    await expect(connect).toBeVisible();

    connection = 'connected';
    const popup = page.waitForEvent('popup');
    await connect.click();
    expect((await popup).url()).toContain('/account/github/connect');

    await expect(
        panel(page).getByRole('button', { name: 'Send', exact: true }),
    ).toBeEnabled();
    await expect(panel(page).getByLabel('Message')).toHaveValue(
        'Needs a second look',
    );
});

test('the last verdict shows its result and offers to connect again', async ({
    page,
}) => {
    await openLockedPage(page, () =>
        answer({
            latestVerdict: {
                id: 'v1',
                kind: 'request-changes',
                message: 'Fix it',
                createdAt: '2026-10-08T10:00:00+00:00',
                deliveries: [
                    {
                        pullRequestId: PR_A,
                        label: 'acme/site#12',
                        state: 'refused',
                        reason: 'connection-expired',
                        reviewUrl: null,
                    },
                ],
            },
        }),
    );
    await openPanel(page);

    await expect(
        panel(page).getByText('Last verdict: Request changes'),
    ).toBeVisible();
    await expect(
        panel(page).getByText('Not posted. Your GitHub connection ended.'),
    ).toBeVisible();
    await expect(
        panel(page).getByRole('button', { name: 'Connect again' }),
    ).toBeVisible();
});
