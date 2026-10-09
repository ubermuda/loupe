/** @vitest-environment jsdom */
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import {
    BACKEND,
    PROJECT,
    bootWidget,
    ok,
    openPanel,
    panelRoot,
    rejected,
    resetWidget,
    settle,
} from './support/widget_harness.js';

const CARD = '0197a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
const PR_A = '0197a1b2-0000-7000-8000-00000000000a';
const PR_B = '0197a1b2-0000-7000-8000-00000000000b';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

let originalHistory;
let popup;
let opened;

beforeAll(() => {
    originalHistory = {
        pushState: window.history.pushState,
        replaceState: window.history.replaceState,
    };
});

beforeEach(() => {
    window.sessionStorage.clear();
    opened = [];
    popup = { location: { href: '' }, closed: false, close() {} };
    window.open = (...args) => {
        opened.push(args);
        return popup;
    };
});

afterEach(() => resetWidget(originalHistory));

/** The verdict answer of the server, with the parts a test changes. */
const verdictAnswer = (overrides = {}) => ({
    cardId: CARD,
    connection: { state: 'connected' },
    pullRequests: [{ id: PR_A, label: 'acme/site#12', ownPullRequest: false }],
    notes: [],
    preview: {
        approve: {
            actions: [{ code: 'post-review', label: 'Posts your verdict.' }],
        },
        'request-changes': {
            actions: [{ code: 'post-review', label: 'Posts your verdict.' }],
        },
        comment: {
            actions: [{ code: 'post-review', label: 'Posts your verdict.' }],
        },
    },
    latestVerdict: null,
    ...overrides,
});

/**
 * Boots on a preview page that locks the card. `verdict` is what the GET
 * answers and can be a function, so a test changes it between reads.
 */
function boot({ verdict = verdictAnswer(), post, locked = true, mode } = {}) {
    const fetchMock = bootWidget({
        context: locked ? `card:${CARD}` : null,
        mode,
        respond: (url, init = {}) => {
            const path = String(url).slice(BACKEND.length).split('?')[0];
            if (path === `/api/board/cards/${CARD}/verdict`) {
                const answer =
                    typeof verdict === 'function' ? verdict() : verdict;
                return answer instanceof Error ? rejected(500, {}) : ok(answer);
            }
            if (path === `/api/board/cards/${CARD}/verdicts`) {
                return post
                    ? post(init)
                    : ok({ verdictId: 'v1', kind: 'approve', noteCount: 0 });
            }
            return ok({
                comments: [],
                context: { label: '#7 Preview card', url: null },
            });
        },
    });

    return fetchMock;
}

const buttons = () =>
    [...panelRoot().querySelectorAll('.lp-verdict-btn')].map(
        (b) => b.textContent,
    );
const press = (name) =>
    [...panelRoot().querySelectorAll('.lp-verdict-btn')]
        .find((b) => b.textContent === name)
        .click();
const sendButton = () => panelRoot().getElementById('lp-verdict-send');
const form = () => panelRoot().getElementById('lp-verdict-form');
const sent = (fetchMock) =>
    fetchMock.mock.calls
        .filter(([url]) => String(url).endsWith('/verdicts'))
        .map(([, init]) => JSON.parse(init.body));

async function write(text) {
    const area = panelRoot().getElementById('lp-verdict-message');
    area.value = text;
    area.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

describe('verdict buttons', () => {
    it('show on a locked card with an open pull request', async () => {
        boot();
        await settle();
        openPanel();

        expect(buttons()).toEqual(['Approve', 'Request changes', 'Comment']);
    });

    it('stay hidden when the card has no open pull request', async () => {
        boot({ verdict: verdictAnswer({ pullRequests: [] }) });
        await settle();
        openPanel();

        expect(buttons()).toEqual([]);
    });

    it('stay hidden when the page locks no card', async () => {
        const fetchMock = boot({
            locked: false,
            mode: { mode: 'per-review', cardId: CARD },
        });
        await settle();
        openPanel();

        expect(buttons()).toEqual([]);
        expect(
            fetchMock.mock.calls.some(([url]) =>
                String(url).includes('/verdict'),
            ),
        ).toBe(false);
    });

    it('stay hidden when the verdict cannot be read', async () => {
        boot({ verdict: new Error('down') });
        await settle();
        openPanel();

        expect(buttons()).toEqual([]);
    });

    it('stay hidden in the demo', async () => {
        const fetchMock = bootWidget({
            demo: true,
            project: null,
            context: `card:${CARD}`,
            respond: () =>
                ok({ comments: [], context: { label: 'Demo', url: null } }),
        });
        await settle();
        openPanel();

        expect(buttons()).toEqual([]);
        expect(
            fetchMock.mock.calls.some(([url]) =>
                String(url).includes('/verdict'),
            ),
        ).toBe(false);
    });
});

describe('confirm panel', () => {
    it('names the card, the pull request and what the verdict does, and records nothing yet', async () => {
        const fetchMock = boot();
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(form().textContent).toContain('Approve for #7 Preview card');
        expect(form().textContent).toContain('acme/site#12');
        expect(form().textContent).toContain('Posts your verdict.');
        expect(sent(fetchMock)).toEqual([]);
    });

    it('says when a verdict is recorded in Loupe only', async () => {
        boot({
            verdict: verdictAnswer({ preview: { approve: { actions: [] } } }),
        });
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(form().textContent).toContain('Recorded in Loupe only');
    });

    it('lets an approval go with no message', async () => {
        const fetchMock = boot();
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(sendButton().disabled).toBe(false);
        sendButton().click();
        await settle();

        expect(sent(fetchMock)).toEqual([
            {
                kind: 'approve',
                pullRequestIds: [PR_A],
                message: '',
                submissionId: expect.stringMatching(UUID),
            },
        ]);
        expect(panelRoot().querySelector('.lp-verdict-ok').textContent).toBe(
            'Sent to the workflow',
        );
        expect(form()).toBeNull();
    });

    it.each(['Request changes', 'Comment'])(
        'keeps Send off for %s with no message',
        async (name) => {
            boot();
            await settle();
            openPanel();
            press(name);
            await settle();

            expect(sendButton().disabled).toBe(true);
            await write('   ');
            expect(sendButton().disabled).toBe(true);
            await write('Fix the heading');
            expect(sendButton().disabled).toBe(false);
        },
    );

    it('keeps Send off for a review with an open note and no message', async () => {
        const notes = [
            {
                id: 'n1',
                url: 'https://x.test/',
                body: 'Heading is cut off',
                anchorCount: 1,
            },
        ];
        boot({ verdict: verdictAnswer({ notes }) });
        await settle();
        openPanel();
        press('Request changes');
        await settle();

        expect(form().textContent).toContain('1 note goes with this review');
        expect(form().textContent).toContain('Heading is cut off');
        expect(sendButton().disabled).toBe(true);
        await write('See the notes');
        expect(sendButton().disabled).toBe(false);
    });

    it('counts the notes still open on an approval and still lets it go', async () => {
        const notes = [
            { id: 'n1', url: 'https://x.test/', body: 'One', anchorCount: 0 },
            { id: 'n2', url: 'https://x.test/', body: 'Two', anchorCount: 0 },
        ];
        boot({ verdict: verdictAnswer({ notes }) });
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(form().textContent).toContain('2 notes are still open');
        expect(sendButton().disabled).toBe(false);
    });

    it('asks for a tick when two pull requests are open and sends each ticked one', async () => {
        const fetchMock = boot({
            verdict: verdictAnswer({
                pullRequests: [
                    { id: PR_A, label: 'acme/site#12', ownPullRequest: false },
                    { id: PR_B, label: 'acme/api#40', ownPullRequest: false },
                ],
            }),
        });
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(sendButton().disabled).toBe(true);
        expect(form().querySelectorAll('[data-pr]')).toHaveLength(2);
        // Each tick repaints the form, so look the next box up again.
        form().querySelector(`[data-pr="${PR_A}"]`).click();
        await settle();
        form().querySelector(`[data-pr="${PR_B}"]`).click();
        await settle();
        sendButton().click();
        await settle();

        expect(sent(fetchMock)[0].pullRequestIds).toEqual([PR_A, PR_B]);
    });

    it('warns when a ticked pull request is the reviewer’s own', async () => {
        boot({
            verdict: verdictAnswer({
                pullRequests: [
                    { id: PR_A, label: 'acme/site#12', ownPullRequest: true },
                ],
            }),
        });
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(form().textContent).toContain(
            'You opened this pull request, so GitHub accepts no verdict from you. The review goes as a comment.',
        );
    });

    it('keeps the draft and shows the reason when the server refuses', async () => {
        boot({ post: () => rejected(409, { error: 'card_closed' }) });
        await settle();
        openPanel();
        press('Comment');
        await settle();
        await write('Looks odd');
        sendButton().click();
        await settle();

        expect(form().textContent).toContain(
            'This card is closed, so it takes no verdict.',
        );
        expect(panelRoot().getElementById('lp-verdict-message').value).toBe(
            'Looks odd',
        );
    });

    it('keeps the submission id when the same verdict is sent again after a failure', async () => {
        let calls = 0;
        const fetchMock = boot({
            post: () =>
                ++calls === 1
                    ? rejected(500, {})
                    : ok({ verdictId: 'v1', kind: 'comment', noteCount: 0 }),
        });
        await settle();
        openPanel();
        press('Comment');
        await settle();
        await write('Looks odd');
        sendButton().click();
        await settle();
        sendButton().click();
        await settle();

        const [first, second] = sent(fetchMock);
        expect(first.submissionId).toMatch(UUID);
        expect(second.submissionId).toBe(first.submissionId);
    });

    it('makes a new submission id when the message changes after a failure', async () => {
        let calls = 0;
        const fetchMock = boot({
            post: () =>
                ++calls === 1
                    ? rejected(500, {})
                    : ok({ verdictId: 'v1', kind: 'comment', noteCount: 0 }),
        });
        await settle();
        openPanel();
        press('Comment');
        await settle();
        await write('Looks odd');
        sendButton().click();
        await settle();
        await write('Looks very odd');
        sendButton().click();
        await settle();

        const [first, second] = sent(fetchMock);
        expect(second.submissionId).not.toBe(first.submissionId);
    });

    it('cancel leaves nothing sent', async () => {
        const fetchMock = boot();
        await settle();
        openPanel();
        press('Approve');
        await settle();
        panelRoot().getElementById('lp-verdict-cancel').click();
        await settle();

        expect(form()).toBeNull();
        expect(sent(fetchMock)).toEqual([]);
    });
});

describe('connecting GitHub', () => {
    const disconnected = (state = 'none') =>
        verdictAnswer({ connection: { state } });

    it('replaces Send with a connect button when the review needs the account', async () => {
        boot({ verdict: disconnected() });
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(sendButton()).toBeNull();
        expect(
            panelRoot().getElementById('lp-verdict-connect').textContent,
        ).toBe('Connect GitHub to send this review');
    });

    it('keeps Send when the verdict writes nothing to GitHub', async () => {
        boot({
            verdict: verdictAnswer({
                connection: { state: 'none' },
                preview: { approve: { actions: [] } },
            }),
        });
        await settle();
        openPanel();
        press('Approve');
        await settle();

        expect(sendButton()).not.toBeNull();
    });

    it('opens the pop-up in the click, then reads again and keeps the draft', async () => {
        let state = 'none';
        const fetchMock = boot({ verdict: () => disconnected(state) });
        await settle();
        openPanel();
        press('Comment');
        await settle();
        await write('Needs work');

        panelRoot().getElementById('lp-verdict-connect').click();
        expect(opened).toHaveLength(1);
        const url = new URL(opened[0][0]);
        expect(url.origin).toBe(BACKEND);
        expect(url.pathname).toBe('/account/github/connect');
        expect(url.searchParams.get('project')).toBe(PROJECT);
        expect(url.searchParams.get('origin')).toBe(window.location.origin);

        const reads = () =>
            fetchMock.mock.calls.filter(([u]) =>
                String(u).endsWith(`/cards/${CARD}/verdict`),
            ).length;
        const before = reads();
        state = 'connected';
        window.dispatchEvent(
            new MessageEvent('message', {
                origin: BACKEND,
                data: { type: 'loupe-github-connect', connected: true },
            }),
        );
        await settle();

        expect(reads()).toBe(before + 1);
        expect(sendButton()).not.toBeNull();
        expect(panelRoot().getElementById('lp-verdict-message').value).toBe(
            'Needs work',
        );
    });

    it('ignores a message from another origin', async () => {
        const fetchMock = boot({ verdict: disconnected() });
        await settle();
        openPanel();
        press('Approve');
        await settle();
        panelRoot().getElementById('lp-verdict-connect').click();
        const before = fetchMock.mock.calls.length;
        window.dispatchEvent(
            new MessageEvent('message', {
                origin: 'https://evil.example',
                data: { type: 'loupe-github-connect', connected: true },
            }),
        );
        await settle();

        expect(fetchMock.mock.calls.length).toBe(before);
    });

    it('says so when the browser blocks the pop-up, and keeps the draft', async () => {
        boot({ verdict: disconnected() });
        await settle();
        openPanel();
        press('Comment');
        await settle();
        await write('Draft');
        window.open = () => null;
        panelRoot().getElementById('lp-verdict-connect').click();
        await settle();

        expect(form().textContent).toContain(
            'Your browser blocked the GitHub window.',
        );
        expect(panelRoot().getElementById('lp-verdict-message').value).toBe(
            'Draft',
        );
    });
});

describe('result of the last verdict', () => {
    const latest = (deliveries) => ({
        id: 'v1',
        kind: 'request-changes',
        message: 'Fix it',
        createdAt: '2026-10-08T10:00:00+00:00',
        deliveries,
    });
    const delivery = (extra) => ({
        pullRequestId: PR_A,
        label: 'acme/site#12',
        state: 'posted',
        reason: null,
        reviewUrl: 'https://github.com/acme/site/pull/12#pullrequestreview-1',
        ...extra,
    });
    const result = () => panelRoot().getElementById('lp-verdict-result');

    it('shows the kind and a link to the posted review', async () => {
        boot({
            verdict: verdictAnswer({ latestVerdict: latest([delivery({})]) }),
        });
        await settle();
        openPanel();

        expect(result().textContent).toContain('Last verdict: Request changes');
        expect(result().textContent).toContain('acme/site#12: Review posted');
        expect(result().querySelector('a').href).toBe(
            'https://github.com/acme/site/pull/12#pullrequestreview-1',
        );
    });

    it('does not link a review url that is not https', async () => {
        boot({
            verdict: verdictAnswer({
                latestVerdict: latest([
                    delivery({ reviewUrl: 'javascript:alert(1)' }),
                ]),
            }),
        });
        await settle();
        openPanel();

        expect(result().querySelector('a')).toBeNull();
    });

    it('explains an expired connection and offers to connect again', async () => {
        boot({
            verdict: verdictAnswer({
                latestVerdict: latest([
                    delivery({
                        state: 'refused',
                        reason: 'connection-expired',
                        reviewUrl: null,
                    }),
                ]),
            }),
        });
        await settle();
        openPanel();

        expect(result().textContent).toContain(
            'Not posted. Your GitHub connection ended.',
        );
        panelRoot().getElementById('lp-verdict-again').click();
        expect(opened).toHaveLength(1);
    });

    it('shows a result even when no pull request is open now', async () => {
        boot({
            verdict: verdictAnswer({
                pullRequests: [],
                latestVerdict: latest([
                    delivery({ state: 'skipped', reason: 'not-open' }),
                ]),
            }),
        });
        await settle();
        openPanel();

        expect(buttons()).toEqual([]);
        expect(result().textContent).toContain(
            'The pull request is no longer open.',
        );
    });
});
