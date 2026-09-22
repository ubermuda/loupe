/** @vitest-environment jsdom */
import { createHash } from 'node:crypto';
import {
    afterEach,
    beforeAll,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
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
    storageKeyFor,
} from './support/widget_harness.js';

const STORAGE_KEY = storageKeyFor(PROJECT);

let originalHistory;
let popup;

beforeAll(() => {
    originalHistory = {
        pushState: window.history.pushState,
        replaceState: window.history.replaceState,
    };
});

beforeEach(() => {
    window.sessionStorage.clear();
    popup = { location: { href: '' }, closed: false, close() {} };
    window.open = () => popup;
});

afterEach(() => resetWidget(originalHistory));

/** A token endpoint answer the widget accepts. */
const tokens = (access, refresh, expiresIn = 3600) =>
    ok({
        token_type: 'Bearer',
        access_token: access,
        refresh_token: refresh,
        expires_in: expiresIn,
    });

/** Routes each request by path, so a test says what every endpoint answers. */
const router = (routes) => (url, options) => {
    const path = String(url).slice(BACKEND.length).split('?')[0];
    const route = routes[path];
    if (!route) throw new Error(`unexpected request to ${path}`);
    return route(options || {});
};

const signInButton = () => panelRoot().getElementById('lp-sign-in');

/** Whether the panel shows the sign-in card rather than the comment list. */
const signInShown = () =>
    panelRoot().getElementById('lp-fatal').style.display !== 'none' &&
    signInButton() !== null;

/**
 * Presses Sign in and waits until the popup is sent to the authorize page.
 * The PKCE digest is async, so a fixed number of ticks is not enough.
 */
const signInAndWaitForPopup = async () => {
    signInButton().click();
    await vi.waitFor(() => expect(popup.location.href).not.toBe(''));
};

const authorizeParameters = () => new URL(popup.location.href).searchParams;

const post = (data, origin = BACKEND) =>
    window.dispatchEvent(new MessageEvent('message', { data, origin }));

const answer = (overrides = {}) => ({
    type: 'loupe-site-review-oauth',
    state: authorizeParameters().get('state'),
    iss: BACKEND,
    code: 'the-code',
    ...overrides,
});

const base64Url = (buffer) =>
    buffer
        .toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');

const tokenCalls = (fetchMock) =>
    fetchMock.mock.calls
        .filter(([url]) => String(url) === `${BACKEND}/oauth/token`)
        .map(([, options]) => new URLSearchParams(options.body));

const bearerOf = (fetchMock, path) =>
    fetchMock.mock.calls
        .filter(([url]) => String(url).startsWith(`${BACKEND}${path}`))
        .map(([, options]) => options.headers.Authorization);

describe('an embed with a project and no token', () => {
    it('asks the reviewer to sign in and calls nothing before that', async () => {
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({}),
        });
        await settle();
        openPanel();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(signInShown()).toBe(true);
        expect(
            panelRoot().getElementById('lp-launch-alert').style.display,
        ).toBe('none');
    });

    it('opens the authorize page for the widget client with S256 PKCE', async () => {
        bootWidget({ project: PROJECT, signedIn: false, respond: router({}) });
        await settle();
        openPanel();

        await signInAndWaitForPopup();

        const url = new URL(popup.location.href);
        expect(url.origin + url.pathname).toBe(`${BACKEND}/oauth/authorize`);
        const parameters = authorizeParameters();
        expect(parameters.get('response_type')).toBe('code');
        expect(parameters.get('client_id')).toBe('loupe-site-review-widget');
        expect(parameters.get('redirect_uri')).toBe(
            `${BACKEND}/oauth/widget/callback`,
        );
        expect(parameters.get('scope')).toBe('site-review');
        expect(parameters.get('code_challenge_method')).toBe('S256');
        expect(parameters.get('project')).toBe(PROJECT);
        expect(parameters.get('origin')).toBe(window.location.origin);
        expect(parameters.get('state').length).toBeGreaterThanOrEqual(20);
    });

    it('exchanges the code with the verifier behind the challenge, then loads', async () => {
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({
                '/oauth/token': () => tokens('access-1', 'refresh-1'),
                '/api/site-review/review': () => ok({ comments: [] }),
            }),
        });
        await settle();
        openPanel();
        await signInAndWaitForPopup();

        post(answer());
        await settle();
        await settle();

        const [exchange] = tokenCalls(fetchMock);
        expect(exchange.get('grant_type')).toBe('authorization_code');
        expect(exchange.get('code')).toBe('the-code');
        expect(exchange.get('client_id')).toBe('loupe-site-review-widget');
        expect(exchange.get('redirect_uri')).toBe(
            `${BACKEND}/oauth/widget/callback`,
        );
        const challenge = base64Url(
            createHash('sha256').update(exchange.get('code_verifier')).digest(),
        );
        expect(challenge).toBe(authorizeParameters().get('code_challenge'));
        expect(
            fetchMock.mock.calls.find(
                ([url]) => String(url) === `${BACKEND}/oauth/token`,
            )[1].credentials,
        ).toBe('omit');

        expect(bearerOf(fetchMock, '/api/site-review/review')).toEqual([
            'Bearer access-1',
        ]);
        expect(
            JSON.parse(window.sessionStorage.getItem(STORAGE_KEY)),
        ).toMatchObject({ accessToken: 'access-1', refreshToken: 'refresh-1' });
        expect(signInShown()).toBe(false);
    });

    it('ignores an answer from another origin', async () => {
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({}),
        });
        await settle();
        openPanel();
        await signInAndWaitForPopup();

        post(answer(), 'https://evil.example');
        await settle();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(signInShown()).toBe(true);
    });

    it('ignores an answer carrying another state', async () => {
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({}),
        });
        await settle();
        openPanel();
        await signInAndWaitForPopup();

        post(answer({ state: 'somebody-elses-state' }));
        await settle();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('refuses an answer from an unexpected issuer', async () => {
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({}),
        });
        await settle();
        openPanel();
        await signInAndWaitForPopup();

        post(answer({ iss: 'https://evil.example' }));
        await settle();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(panelRoot().getElementById('lp-fatal').textContent).toContain(
            'unexpected server',
        );
    });

    it('says so when the reviewer denies the sign-in', async () => {
        bootWidget({ project: PROJECT, signedIn: false, respond: router({}) });
        await settle();
        openPanel();
        await signInAndWaitForPopup();

        post(answer({ code: undefined, error: 'access_denied' }));
        await settle();

        expect(panelRoot().getElementById('lp-fatal').textContent).toContain(
            'cancelled',
        );
        expect(signInShown()).toBe(true);
    });
});

describe('a stored grant', () => {
    const store = (grant) =>
        window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(grant));

    it('is used as it is while it is fresh', async () => {
        store({
            accessToken: 'stored',
            refreshToken: 'r',
            expiresAt: Date.now() + 3600_000,
        });
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({
                '/api/site-review/review': () => ok({ comments: [] }),
            }),
        });
        await settle();

        expect(bearerOf(fetchMock, '/api/site-review/review')).toEqual([
            'Bearer stored',
        ]);
        expect(tokenCalls(fetchMock)).toEqual([]);
    });

    it('is refreshed before a request when it is about to expire', async () => {
        store({
            accessToken: 'old',
            refreshToken: 'refresh-old',
            expiresAt: Date.now() + 1000,
        });
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({
                '/oauth/token': () => tokens('new', 'refresh-new'),
                '/api/site-review/review': () => ok({ comments: [] }),
            }),
        });
        await settle();
        await settle();

        const [refresh] = tokenCalls(fetchMock);
        expect(refresh.get('grant_type')).toBe('refresh_token');
        expect(refresh.get('refresh_token')).toBe('refresh-old');
        expect(bearerOf(fetchMock, '/api/site-review/review')).toEqual([
            'Bearer new',
        ]);
    });

    it('is refreshed once and the call retried after a 401', async () => {
        store({
            accessToken: 'revoked',
            refreshToken: 'refresh-1',
            expiresAt: Date.now() + 3600_000,
        });
        const fetchMock = bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({
                '/oauth/token': () => tokens('fresh', 'refresh-2'),
                '/api/site-review/review': (options) =>
                    options.headers.Authorization === 'Bearer fresh'
                        ? ok({ comments: [] })
                        : rejected(401, { error: 'unauthorized' }),
            }),
        });
        await settle();
        await settle();

        expect(bearerOf(fetchMock, '/api/site-review/review')).toEqual([
            'Bearer revoked',
            'Bearer fresh',
        ]);
        openPanel();
        expect(signInShown()).toBe(false);
    });

    it('is dropped when the refresh is refused, and the sign-in comes back', async () => {
        store({
            accessToken: 'revoked',
            refreshToken: 'refresh-revoked',
            expiresAt: Date.now() + 3600_000,
        });
        bootWidget({
            project: PROJECT,
            signedIn: false,
            respond: router({
                '/oauth/token': () => rejected(400, { error: 'invalid_grant' }),
                '/api/site-review/review': () =>
                    rejected(401, { error: 'unauthorized' }),
            }),
        });
        await settle();
        await settle();
        openPanel();

        expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull();
        expect(signInShown()).toBe(true);
    });
});
