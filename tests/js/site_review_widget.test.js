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
