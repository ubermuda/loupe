import { describe, expect, it } from 'vitest';
import {
    keepBreadcrumb,
    redact,
    scrubEvent,
    scrubSelector,
    scrubSpan,
    scrubTransaction,
} from '../../assets/lib/sentry_scrub.js';

describe('redact', () => {
    it('keeps only the origin of a URL', () => {
        expect(
            redact(
                'Failed at https://app.test:8443/reset/abc123?token=x#y now',
            ),
        ).toBe('Failed at https://app.test:8443 now');
    });

    it('replaces an email address', () => {
        expect(redact('No user ada@example.org found')).toBe(
            'No user [email] found',
        );
    });

    it('replaces a same-origin path with no scheme', () => {
        expect(
            redact(
                'GET /forgot-password/reset/SECRET123?x=1 failed ("/beta/tok")',
            ),
        ).toBe('GET [path] failed ("[path]")');
    });

    it('keeps an asset path and a protocol-relative URL host', () => {
        expect(redact('at /assets/app-abc.js')).toBe('at /assets/app-abc.js');
        expect(redact('a/b and 1/2')).toBe('a/b and 1/2');
    });

    it('drops a URL that has no host', () => {
        expect(redact('see file:///etc/passwd')).toBe('see [url]');
    });
});

describe('scrubSelector', () => {
    it('drops attribute values and keeps tag, id and classes', () => {
        expect(
            scrubSelector(
                'div#board.lp-board > span.lp-board-card__parent[title="Fix the login page"]',
            ),
        ).toBe('div#board.lp-board > span.lp-board-card__parent');
    });

    it('drops several attributes on one element', () => {
        expect(
            scrubSelector(
                'button.lp-btn[aria-label="Delete Ada\'s card"][type="submit"]',
            ),
        ).toBe('button.lp-btn');
    });

    it('filters the whole selector when a value hides a quote', () => {
        expect(scrubSelector('span[title="a"] > b"][type="submit"]')).toBe(
            '[Filtered]',
        );
    });
});

describe('keepBreadcrumb', () => {
    it.each(['fetch', 'xhr', 'navigation', 'console'])(
        'drops a %s breadcrumb',
        (category) => {
            expect(keepBreadcrumb({ category, data: {} })).toBeNull();
        },
    );

    it('keeps a click breadcrumb without attribute values', () => {
        expect(
            keepBreadcrumb({
                category: 'ui.click',
                message: 'a.lp-link[title="Secret card"]',
            }),
        ).toEqual({ category: 'ui.click', message: 'a.lp-link' });
    });

    it('keeps another breadcrumb as it is', () => {
        const crumb = { category: 'sentry.event', message: 'x' };
        expect(keepBreadcrumb(crumb)).toBe(crumb);
    });
});

describe('scrubEvent', () => {
    it('removes the page URL, the query and the Referer', () => {
        const event = scrubEvent({
            request: {
                url: 'https://app.test/reset/abc',
                query_string: 'token=x',
                headers: {
                    Referer: 'https://app.test/a',
                    'User-Agent': 'UA',
                },
            },
        });
        expect(event.request).toEqual({ headers: { 'User-Agent': 'UA' } });
    });

    it('redacts the message, the log entry and the exception values', () => {
        const event = scrubEvent({
            message: 'see https://app.test/x?y=1',
            logentry: {
                message: 'mail ada@example.org',
                formatted: 'at https://app.test/z',
            },
            exception: {
                values: [
                    { type: 'Error', value: 'GET https://app.test/q 500' },
                ],
            },
        });
        expect(event.message).toBe('see https://app.test');
        expect(event.logentry).toEqual({
            message: 'mail [email]',
            formatted: 'at https://app.test',
        });
        expect(event.exception.values[0].value).toBe(
            'GET https://app.test 500',
        );
    });

    it('keeps asset frames and redacts a frame on the page itself', () => {
        const event = scrubEvent({
            exception: {
                values: [
                    {
                        value: 'boom',
                        stacktrace: {
                            frames: [
                                {
                                    filename:
                                        'https://app.test/assets/app-1a2b.js',
                                    abs_path:
                                        'https://app.test/assets/app-1a2b.js',
                                },
                                {
                                    filename: 'https://app.test/reset/abc',
                                    abs_path: 'https://app.test/reset/abc',
                                },
                            ],
                        },
                    },
                ],
            },
        });
        expect(event.exception.values[0].stacktrace.frames).toEqual([
            {
                filename: 'https://app.test/assets/app-1a2b.js',
                abs_path: 'https://app.test/assets/app-1a2b.js',
            },
            { filename: 'https://app.test', abs_path: 'https://app.test' },
        ]);
    });

    it('drops URL data from the breadcrumbs it carries', () => {
        const event = scrubEvent({
            breadcrumbs: [
                {
                    category: 'sentry.event',
                    message: 'at https://app.test/x',
                    data: { url: '/x', from: '/a', to: '/b', status: 1 },
                },
            ],
        });
        expect(event.breadcrumbs).toEqual([
            {
                category: 'sentry.event',
                message: 'at https://app.test',
                data: { status: 1 },
            },
        ]);
    });

    it('names an error event after the route', () => {
        expect(
            scrubEvent({ transaction: '/reset/abc' }, 'app_reset_password')
                .transaction,
        ).toBe('app_reset_password');
    });

    it('drops the name of an error event when there is no route', () => {
        expect(scrubEvent({ transaction: '/reset/abc' }, null)).toEqual({});
    });

    it('keeps the name of a transaction when there is no route', () => {
        expect(
            scrubEvent({ type: 'transaction', transaction: 'pageload' }, null),
        ).toEqual({ type: 'transaction', transaction: 'pageload' });
    });

    it('accepts an event with nothing to scrub', () => {
        expect(scrubEvent({})).toEqual({});
    });
});

describe('scrubTransaction', () => {
    it('keeps the name when no route is given', () => {
        expect(scrubTransaction({ transaction: 'x' }, null).transaction).toBe(
            'x',
        );
    });

    it('names the trace and span data after the route', () => {
        const event = scrubTransaction(
            {
                contexts: {
                    trace: {
                        data: { 'sentry.segment.name': '/reset/abc' },
                    },
                },
                spans: [{ data: { 'sentry.transaction': '/reset/abc' } }],
            },
            'app_reset_password',
        );
        expect(event.contexts.trace.data).toEqual({
            'sentry.segment.name': 'app_reset_password',
        });
        expect(event.spans[0].data).toEqual({
            'sentry.transaction': 'app_reset_password',
        });
    });

    it('drops URL data from the trace and from each span', () => {
        const event = scrubTransaction(
            {
                contexts: {
                    trace: {
                        data: {
                            'url.full': 'https://app.test/x?y',
                            'url.path': '/x',
                            'sentry.op': 'pageload',
                            'lcp.url': 'https://app.test/i.png',
                            'lcp.element': 'img.cover[alt="Ada"]',
                            'cls.source.1': 'div[title="Ada"]',
                        },
                    },
                },
                spans: [
                    {
                        op: 'http.client',
                        description: 'GET /projects/1/cards?q=secret',
                        data: {
                            url: '/projects/1/cards',
                            'http.query': '?q=secret',
                            'http.fragment': '#x',
                            'url.query': '?q=secret',
                            'http.method': 'GET',
                        },
                    },
                    {
                        op: 'resource.script',
                        description: 'https://app.test/assets/app-1a2b.js',
                        data: {
                            'code.file.path': 'https://app.test/reset/abc',
                        },
                    },
                ],
            },
            'app_board_show',
        );
        expect(event.contexts.trace.data).toEqual({
            'sentry.op': 'pageload',
            'lcp.element': 'img.cover',
            'cls.source.1': 'div',
        });
        expect(event.spans).toEqual([
            {
                op: 'http.client',
                description: 'GET',
                data: { 'http.method': 'GET' },
            },
            {
                op: 'resource.script',
                description: 'https://app.test/assets/app-1a2b.js',
                data: { 'code.file.path': 'https://app.test' },
            },
        ]);
    });
});

describe('scrubSpan', () => {
    it('cuts an http.client name to its method and drops URL data', () => {
        const span = scrubSpan({
            op: 'http.client',
            description: 'POST /cards/12/move',
            data: {
                'url.full': 'https://app.test/cards/12/move',
                'url.path': '/cards/12/move',
            },
        });
        expect(span).toEqual({
            op: 'http.client',
            description: 'POST',
            data: {},
        });
    });

    it('drops attribute values from an interaction span', () => {
        const span = scrubSpan({
            op: 'ui.interaction.click',
            description: 'button.lp-btn[aria-label="Ada"]',
            data: {
                'browser.web_vital.inp.target':
                    'button.lp-btn[aria-label="Ada"]',
            },
        });
        expect(span).toEqual({
            op: 'ui.interaction.click',
            description: 'button.lp-btn',
            data: { 'browser.web_vital.inp.target': 'button.lp-btn' },
        });
    });

    it('names the span data after the route', () => {
        const span = scrubSpan(
            {
                op: 'ui.webvital.cls',
                data: {
                    'sentry.transaction': '/reset/abc',
                    'sentry.segment.name': '/reset/abc',
                },
            },
            'app_reset_password',
        );
        expect(span.data).toEqual({
            'sentry.transaction': 'app_reset_password',
            'sentry.segment.name': 'app_reset_password',
        });
    });

    it('drops the page path from the span data when there is no route', () => {
        const span = scrubSpan({
            op: 'ui.webvital.cls',
            data: {
                'sentry.transaction': '/reset/abc',
                'sentry.segment.name': '/reset/abc',
                'sentry.op': 'ui.webvital.cls',
            },
        });
        expect(span.data).toEqual({ 'sentry.op': 'ui.webvital.cls' });
    });

    it('accepts a span with no data', () => {
        expect(
            scrubSpan({
                op: 'ui.long_animation_frame',
                description: 'Main UI thread blocked',
            }),
        ).toEqual({
            op: 'ui.long_animation_frame',
            description: 'Main UI thread blocked',
        });
    });
});
