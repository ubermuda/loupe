/** @vitest-environment jsdom */
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
    BACKEND,
    bootWidget,
    hostCount,
    ok,
    openPanel,
    panelRoot,
    rejected,
    resetWidget,
    settle,
    TOKEN,
} from './support/widget_harness.js';

let originalHistory;

beforeAll(() => {
    originalHistory = {
        pushState: window.history.pushState,
        replaceState: window.history.replaceState,
    };
});

afterEach(() => resetWidget(originalHistory));

/** The critical panel's detail line, which names the fix for this rejection. */
function fatalDetail() {
    openPanel();

    return panelRoot().getElementById('lp-fatal').querySelector('.lp-fatal-sub')
        .textContent;
}

/** Whether the collapsed launcher shows its rejected-token badge. */
function alerting() {
    return (
        panelRoot().getElementById('lp-launch-alert').style.display !== 'none'
    );
}

describe('an unresolved page marker', () => {
    it('is dropped from the save, not only from the label', async () => {
        // The resolver answers null for a card that is deleted, malformed or
        // another project's. Keeping the marker would file the comment against
        // it anyway, while the composer said it was attached to nothing.
        const fetchMock = bootWidget({
            context: 'card:01a0-deleted',
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('general').click();
        const textarea = root.getElementById('lp-textarea');
        textarea.value = 'Something is wrong here';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        root.getElementById('lp-save').click();
        await settle();

        const save = fetchMock.mock.calls.find(
            ([, init]) => init && init.method === 'POST',
        );
        expect(save).toBeTruthy();
        expect(JSON.parse(save[1].body)).not.toHaveProperty('context');
    });
});

describe('a card chosen for one comment', () => {
    it('does not outlive the draft that chose it', async () => {
        // The page's marker says what this preview is for. An override that
        // outlived its draft would make that mean less with every comment:
        // detach once, and every later comment would stay detached.
        bootWidget({
            context: 'card:01a0-page',
            respond: () =>
                ok({
                    comments: [],
                    context: {
                        label: '#1 The card this page is for',
                        url: null,
                    },
                }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('general').click();
        await settle();
        expect(root.querySelector('.lp-context-label').textContent).toBe(
            '#1 The card this page is for',
        );

        root.querySelector('.lp-context-label').click();
        await settle();
        root.getElementById('lp-picker-detach').click();
        await settle();
        expect(root.querySelector('.lp-context-label').textContent).toBe(
            'Attach to a card',
        );

        root.getElementById('lp-cancel').click();
        root.getElementById('general').click();
        await settle();

        expect(root.querySelector('.lp-context-label').textContent).toBe(
            '#1 The card this page is for',
        );
    });

    it('does not outlive a draft that was saved either', async () => {
        // Saving ends a draft as surely as cancelling does, and its teardown is
        // a separate block.
        bootWidget({
            context: 'card:01a0-page',
            respond: () =>
                ok({
                    comments: [],
                    context: {
                        label: '#1 The card this page is for',
                        url: null,
                    },
                    commentId: 'c1',
                }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('general').click();
        await settle();
        root.querySelector('.lp-context-label').click();
        await settle();
        root.getElementById('lp-picker-detach').click();
        await settle();

        const textarea = root.getElementById('lp-textarea');
        textarea.value = 'Detached for this one only';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        root.getElementById('lp-save').click();
        await settle();

        root.getElementById('general').click();
        await settle();

        expect(root.querySelector('.lp-context-label').textContent).toBe(
            '#1 The card this page is for',
        );
    });
});

describe('a token revoked while the picker is open', () => {
    it('shows the critical panel rather than a stuck Loading', async () => {
        const fetchMock = bootWidget({
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('general').click();
        await settle();

        fetchMock.mockImplementation(async () =>
            rejected(403, { error: 'token_not_bound_to_site' }),
        );
        root.querySelector('.lp-context-label').click();
        await settle();

        expect(root.getElementById('lp-fatal').style.display).not.toBe('none');
    });
});

describe('navigating to a page that names a different card', () => {
    /** Turbo swaps the tag after the URL changes, so the marker lands late. */
    async function navigate(to, marker) {
        const tag = document.querySelector(
            'script[src*="site-review/widget.js"]',
        );
        window.history.pushState({}, '', to);
        // The swap happens after pushState, which is the whole difficulty.
        if (marker === null) tag.removeAttribute('data-context');
        else tag.setAttribute('data-context', marker);
        await new Promise((resolve) => setTimeout(resolve, 300));
        await settle();
    }

    it('re-resolves against the new page marker', async () => {
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();

        await navigate('/second', 'card:second');

        const asked = fetchMock.mock.calls.map(([url]) => url);
        expect(asked.some((url) => url.includes('context=card%3Asecond'))).toBe(
            true,
        );
    });

    it('never pairs one page label with another page marker', async () => {
        // Two refreshes overlap when navigation is quick. Deriving the marker
        // at response time rather than at request time let the row show the
        // first page's card while the save carried the second page's.
        const answers = [];
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () => {
                const label = { label: '#1 First page card', url: null };
                const payload = ok({ comments: [], context: label });
                answers.push(payload);

                return payload;
            },
        });
        await settle();

        // The second page resolves to nothing, so its marker must be dropped
        // and the first page's label must not survive alongside it.
        fetchMock.mockImplementation(async () =>
            ok({ comments: [], context: null }),
        );
        await navigate('/second', 'card:second');

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('general').click();
        const textarea = root.getElementById('lp-textarea');
        textarea.value = 'About the second page';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        root.getElementById('lp-save').click();
        await settle();

        expect(root.querySelector('.lp-context-label').textContent).toBe(
            'Attach to a card',
        );
        const save = fetchMock.mock.calls.find(
            ([, init]) => init && init.method === 'POST',
        );
        expect(JSON.parse(save[1].body)).not.toHaveProperty('context');
    });

    it('detaches the moment the marker changes, not when the answer lands', async () => {
        // A reviewer who saves while the new page's context is still resolving,
        // or after that request fails, must not file against the page they have
        // already left.
        let release;
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () =>
                ok({
                    comments: [],
                    context: { label: '#1 First page card', url: null },
                }),
        });
        await settle();

        const root = panelRoot();
        root.getElementById('lp-launch-main').click();
        root.getElementById('general').click();
        await settle();
        expect(root.querySelector('.lp-context-label').textContent).toBe(
            '#1 First page card',
        );

        // The next answer never arrives, which is the window under test.
        fetchMock.mockImplementation(
            () => new Promise((resolve) => (release = resolve)),
        );
        await navigate('/second', 'card:second');

        expect(release).toBeTypeOf('function');
        expect(root.querySelector('.lp-context-label').textContent).toBe(
            'Attach to a card',
        );

        const textarea = root.getElementById('lp-textarea');
        textarea.value = 'Saved while the new page was still resolving';
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();
        fetchMock.mockImplementation(async () => ok({ commentId: 'c9' }));
        root.getElementById('lp-save').click();
        await settle();

        const save = fetchMock.mock.calls.find(
            ([, init]) => init && init.method === 'POST',
        );
        expect(save).toBeTruthy();
        expect(JSON.parse(save[1].body)).not.toHaveProperty('context');
    });

    it('drops the marker when the new page names no card', async () => {
        // The fallback for a missing tag must not apply to a tag that is
        // present and says nothing: that is the page stating it names no card.
        const fetchMock = bootWidget({
            context: 'card:first',
            respond: () => ok({ comments: [], context: null }),
        });
        await settle();
        fetchMock.mockClear();

        await navigate('/plain', null);

        const asked = fetchMock.mock.calls.map(([url]) => url);
        expect(asked.length).toBeGreaterThan(0);
        expect(asked.every((url) => !url.includes('context='))).toBe(true);
    });
});

describe('boot', () => {
    it('reads the backend from its own src and sends the data-token', async () => {
        const fetchMock = bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe(`${BACKEND}/api/site-review/review`);
        expect(options.method).toBe('GET');
        expect(options.headers.Authorization).toBe(`Bearer ${TOKEN}`);
    });

    it('attaches its own shadow roots to the document element', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        expect(hostCount()).toBe(2);
        expect(panelRoot().getElementById('lp-launcher')).not.toBeNull();
    });

    it('runs once however often the script tag is executed', async () => {
        bootWidget({ respond: () => ok({ comments: [] }) });
        bootWidget({ respond: () => ok({ comments: [] }) });
        await settle();

        expect(hostCount()).toBe(2);
    });

    it('shows the comment count and no alert on a good load', async () => {
        bootWidget({
            respond: () =>
                ok({
                    comments: [
                        {
                            id: 1,
                            body: 'a note',
                            url: 'about:blank',
                            anchors: [],
                        },
                    ],
                }),
        });
        await settle();

        expect(alerting()).toBe(false);
        expect(panelRoot().getElementById('lp-launch-count').textContent).toBe(
            '1',
        );
    });
});

describe('a rejected token', () => {
    it('names a revoked token for a 401 with no readable body', async () => {
        bootWidget({ respond: () => rejected(401) });
        await settle();

        expect(alerting()).toBe(true);
        expect(fatalDetail()).toContain('invalid or was revoked');
    });

    it('names the wrong token type for insufficient_scope', async () => {
        bootWidget({
            respond: () => rejected(403, { error: 'insufficient_scope' }),
        });
        await settle();

        expect(fatalDetail()).toContain('not another API token');
    });

    it('names an unlinked site for token_not_bound_to_site', async () => {
        bootWidget({
            respond: () => rejected(403, { error: 'token_not_bound_to_site' }),
        });
        await settle();

        expect(fatalDetail()).toContain('linked to a site');
    });

    it('falls back to the generic message for a 403 with no code', async () => {
        bootWidget({ respond: () => rejected(403) });
        await settle();

        expect(fatalDetail()).toContain('token was rejected');
    });

    it('prefers the body code over the status', async () => {
        bootWidget({
            respond: () => rejected(401, { error: 'token_not_bound_to_site' }),
        });
        await settle();

        expect(fatalDetail()).toContain('linked to a site');
    });

    it('hides the comment list behind the critical panel', async () => {
        bootWidget({ respond: () => rejected(401) });
        await settle();
        openPanel();

        expect(panelRoot().getElementById('lp-main').style.display).toBe(
            'none',
        );
        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'block',
        );
    });
});

describe('a transient failure', () => {
    it('leaves a 500 dismissible rather than fatal', async () => {
        bootWidget({ respond: () => rejected(500) });
        await settle();

        expect(alerting()).toBe(false);
        openPanel();
        expect(panelRoot().getElementById('lp-fatal').style.display).toBe(
            'none',
        );
    });

    it('leaves a 429 dismissible rather than fatal', async () => {
        bootWidget({ respond: () => rejected(429, { error: 'too_many' }) });
        await settle();

        expect(alerting()).toBe(false);
    });
});
