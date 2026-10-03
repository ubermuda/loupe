/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initSentry } from '../../assets/lib/sentry.js';
import { keepBreadcrumb } from '../../assets/lib/sentry_scrub.js';
import { reset as resetSpans } from '../../assets/lib/sentry_spans.js';

const DSN = 'https://abc@o1.ingest.sentry.io/2';

function fakeSentry() {
    return {
        init: vi.fn(),
        browserTracingIntegration: vi.fn((options) => ({
            name: 'BrowserTracing',
            options,
        })),
        breadcrumbsIntegration: vi.fn((options) => ({
            name: 'Breadcrumbs',
            options,
        })),
        withStaticSpan: vi.fn((callback) => callback),
        startNewTrace: vi.fn((callback) => callback()),
        startInactiveSpan: vi.fn(() => ({ end: vi.fn(), setStatus: vi.fn() })),
        setActiveSpanInBrowser: vi.fn(),
        getActiveSpan: vi.fn(),
        setTransactionName: vi.fn(),
        getCurrentScope() {
            return { setTransactionName: this.setTransactionName };
        },
    };
}

function renderMeta(values) {
    for (const [name, content] of Object.entries(values)) {
        const meta = document.createElement('meta');
        meta.name = name;
        meta.content = content;
        document.head.append(meta);
    }
}

const PAGE = {
    'loupe-route': 'app_board_show',
    'sentry-browser-dsn': DSN,
    'sentry-browser-traces-sample-rate': '0.25',
    'sentry-release': 'v1.2.3',
    'sentry-environment': 'prod',
};

describe('initSentry', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
    });

    afterEach(() => {
        resetSpans();
        vi.restoreAllMocks();
    });

    it('enables the interaction spans once the SDK starts', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        document.dispatchEvent(new Event('turbo:submit-start'));

        expect(sentry.startInactiveSpan).toHaveBeenCalledWith(
            expect.objectContaining({
                op: 'ui.turbo.submit',
                name: 'app_board_show',
            }),
        );
    });

    it('enables no spans when the page has no DSN', () => {
        const sentry = fakeSentry();
        initSentry(sentry);

        document.dispatchEvent(new Event('turbo:submit-start'));

        expect(sentry.startInactiveSpan).not.toHaveBeenCalled();
    });

    it('keeps a live change name and its numeric attributes', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        const event = sentry.init.mock.calls[0][0].beforeSendTransaction({
            type: 'transaction',
            transaction: 'board.card_changed',
            contexts: {
                trace: {
                    op: 'ui.live',
                    data: { 'loupe.tab_age_ms': 81234, 'loupe.heap_mb': 52 },
                },
            },
        });

        expect(event.transaction).toBe('board.card_changed');
        expect(event.contexts.trace.data).toEqual({
            'loupe.tab_age_ms': 81234,
            'loupe.heap_mb': 52,
        });
    });

    it('does nothing when the SDK did not load', () => {
        renderMeta(PAGE);
        expect(initSentry(undefined)).toBe(false);
    });

    it('does nothing when the page has no DSN', () => {
        const sentry = fakeSentry();
        expect(initSentry(sentry)).toBe(false);
        expect(sentry.init).not.toHaveBeenCalled();
    });

    it('starts the SDK with the page settings and the scrubbers', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();

        expect(initSentry(sentry)).toBe(true);

        const options = sentry.init.mock.calls[0][0];
        expect(options).toMatchObject({
            dsn: DSN,
            release: 'v1.2.3',
            environment: 'prod',
            tracesSampleRate: 0.25,
            traceLifecycle: 'static',
            dataCollection: {
                userInfo: false,
                cookies: false,
                urlQueryParams: false,
            },
            beforeBreadcrumb: keepBreadcrumb,
        });
        expect(options).not.toHaveProperty('tracePropagationTargets');
        expect(options.dataCollection).not.toHaveProperty('httpHeaders');
        expect(sentry.withStaticSpan).toHaveBeenCalledWith(
            options.beforeSendSpan,
        );
        expect(sentry.breadcrumbsIntegration).toHaveBeenCalledWith({
            console: false,
        });
        expect(options.integrations.map(({ name }) => name)).toEqual([
            'BrowserTracing',
            'Breadcrumbs',
        ]);
    });

    it('leaves the release unset when the page has none', () => {
        const { 'sentry-release': _release, ...withoutRelease } = PAGE;
        renderMeta(withoutRelease);
        const sentry = fakeSentry();

        initSentry(sentry);

        expect(sentry.init.mock.calls[0][0].release).toBeUndefined();
    });

    it('names the page load span after the route', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        const options = sentry.browserTracingIntegration.mock.calls[0][0];
        expect(options.instrumentNavigation).toBe(false);
        expect(
            options.beforeStartSpan({
                name: '/projects/1/board',
                op: 'pageload',
                attributes: { a: 1 },
            }),
        ).toEqual({
            name: 'app_board_show',
            op: 'pageload',
            attributes: { a: 1 },
        });
    });

    it('names the span after its op when the page has no route', () => {
        renderMeta({ ...PAGE, 'loupe-route': '' });
        const sentry = fakeSentry();
        initSentry(sentry);

        const options = sentry.browserTracingIntegration.mock.calls[0][0];
        expect(
            options.beforeStartSpan({ name: '/reset/abc', op: 'pageload' })
                .name,
        ).toBe('pageload');
    });

    it('names an error event after the route', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        const event = sentry.init.mock.calls[0][0].beforeSend({
            transaction: '/forgot-password/reset/abc',
            request: { url: 'https://app.test/forgot-password/reset/abc' },
        });

        expect(event).toEqual({ transaction: 'app_board_show', request: {} });
    });

    it('drops the error event name when the page has no route', () => {
        renderMeta({ ...PAGE, 'loupe-route': '' });
        const sentry = fakeSentry();
        initSentry(sentry);

        const event = sentry.init.mock.calls[0][0].beforeSend({
            transaction: '/forgot-password/reset/abc',
        });

        expect(event).toEqual({});
    });

    it('names the span data after the route', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        const span = sentry.init.mock.calls[0][0].beforeSendSpan({
            op: 'ui.webvital.lcp',
            description: 'img.cover',
            data: {
                'sentry.transaction': '/forgot-password/reset/abc',
                'sentry.segment.name': '/forgot-password/reset/abc',
            },
        });

        expect(span.data).toEqual({
            'sentry.transaction': 'app_board_show',
            'sentry.segment.name': 'app_board_show',
        });
    });

    it('replaces the page path in the scope with the route', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        expect(sentry.setTransactionName).toHaveBeenCalledWith(
            'app_board_show',
        );
    });

    it('clears the page path from the scope when the page has no route', () => {
        renderMeta({ ...PAGE, 'loupe-route': '' });
        const sentry = fakeSentry();
        initSentry(sentry);

        expect(sentry.setTransactionName).toHaveBeenCalledWith(undefined);
    });

    it('names the scope after the new route on each Turbo visit', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        document.querySelector('meta[name="loupe-route"]').content =
            'app_card_show';
        document.dispatchEvent(new Event('turbo:load'));

        expect(sentry.setTransactionName).toHaveBeenLastCalledWith(
            'app_card_show',
        );
        const event = sentry.init.mock.calls[0][0].beforeSend({
            transaction: '/cards/1',
        });
        expect(event.transaction).toBe('app_card_show');
    });

    it('keeps the name a transaction started with after a Turbo visit', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        document.querySelector('meta[name="loupe-route"]').content =
            'app_card_show';
        const event = sentry.init.mock.calls[0][0].beforeSendTransaction({
            type: 'transaction',
            transaction: 'app_board_show',
        });

        expect(event.transaction).toBe('app_board_show');
    });

    it('renames a transaction whose name holds a method and a path', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        const event = sentry.init.mock.calls[0][0].beforeSendTransaction({
            type: 'transaction',
            transaction: 'GET /forgot-password/reset/SECRET?token=x',
        });

        expect(event.transaction).toBe('app_board_show');
    });

    it('names a path transaction after its op when the page has no route', () => {
        renderMeta({ ...PAGE, 'loupe-route': '' });
        const sentry = fakeSentry();
        initSentry(sentry);

        const event = sentry.init.mock.calls[0][0].beforeSendTransaction({
            type: 'transaction',
            transaction: '/forgot-password/reset/SECRET',
            contexts: { trace: { op: 'pageload' } },
        });

        expect(event.transaction).toBe('pageload');
    });

    it('scrubs a transaction and names it after the route', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        const event = sentry.init.mock.calls[0][0].beforeSendTransaction({
            type: 'transaction',
            transaction: '/projects/1/board',
            request: { url: 'https://app.test/projects/1/board' },
            contexts: { trace: { data: { 'url.full': 'https://app.test/x' } } },
        });

        expect(event).toEqual({
            type: 'transaction',
            transaction: 'app_board_show',
            request: {},
            contexts: { trace: { data: {} } },
        });
    });
});
