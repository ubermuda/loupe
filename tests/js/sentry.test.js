/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initSentry } from '../../assets/lib/sentry.js';
import {
    keepBreadcrumb,
    scrubEvent,
    scrubSpan,
} from '../../assets/lib/sentry_scrub.js';

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
        withStaticSpan: vi.fn((callback) => {
            callback._static = true;
            return callback;
        }),
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
        vi.restoreAllMocks();
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
            beforeSend: scrubEvent,
            beforeBreadcrumb: keepBreadcrumb,
        });
        expect(options).not.toHaveProperty('tracePropagationTargets');
        expect(options.dataCollection).not.toHaveProperty('httpHeaders');
        expect(sentry.withStaticSpan).toHaveBeenCalledWith(scrubSpan);
        expect(options.beforeSendSpan).toBe(scrubSpan);
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

    it('scrubs a transaction and names it after the route', () => {
        renderMeta(PAGE);
        const sentry = fakeSentry();
        initSentry(sentry);

        const event = sentry.init.mock.calls[0][0].beforeSendTransaction({
            transaction: '/projects/1/board',
            request: { url: 'https://app.test/projects/1/board' },
            contexts: { trace: { data: { 'url.full': 'https://app.test/x' } } },
        });

        expect(event).toEqual({
            transaction: 'app_board_show',
            request: {},
            contexts: { trace: { data: {} } },
        });
    });
});
