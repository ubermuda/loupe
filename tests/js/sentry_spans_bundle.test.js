/** @vitest-environment jsdom */
import { readFileSync } from 'node:fs';
import { beforeAll, expect, it } from 'vitest';
import { initSentry } from '../../assets/lib/sentry.js';
import { now, traceLive } from '../../assets/lib/sentry_spans.js';

const BUNDLE = readFileSync('assets/sentry/bundle.tracing.min.js', 'utf8');
const bodies = [];
let Sentry;
let pageTrace;

function fire(type, detail = {}) {
    document.dispatchEvent(new CustomEvent(type, { detail }));
}

function settle() {
    return new Promise((resolve) => setTimeout(resolve, 50));
}

beforeAll(() => {
    // jsdom has no Performance Timeline API, which the SDK reads at start.
    performance.getEntriesByType = () => [];
    performance.getEntriesByName = () => [];
    performance.mark = () => {};
    performance.measure = () => {};
    (0, eval)(BUNDLE);
    Sentry = window.Sentry;

    for (const [name, content] of Object.entries({
        'loupe-route': 'app_board_show',
        'sentry-browser-dsn': 'https://abc@o1.ingest.sentry.io/2',
        'sentry-browser-traces-sample-rate': '1',
    })) {
        const meta = document.createElement('meta');
        meta.name = name;
        meta.content = content;
        document.head.append(meta);
    }
    const transport = (options) =>
        Sentry.createTransport(options, (request) => {
            bodies.push(
                typeof request.body === 'string'
                    ? request.body
                    : new TextDecoder().decode(request.body),
            );
            return Promise.resolve({ statusCode: 200 });
        });
    initSentry({
        ...Sentry,
        init: (options) => Sentry.init({ ...options, transport }),
    });
});

it('finds every function the span module calls in the bundle', () => {
    for (const name of [
        'startNewTrace',
        'startInactiveSpan',
        'setActiveSpanInBrowser',
        'updateSpanName',
    ]) {
        expect(typeof Sentry[name], name).toBe('function');
    }
});

it('gives each interaction its own trace and makes it the fetch parent', async () => {
    const pageLoad = Sentry.getActiveSpan();
    pageTrace = pageLoad.spanContext().traceId;

    fire('turbo:submit-start');
    const submit = Sentry.getActiveSpan();
    expect(submit).not.toBe(pageLoad);
    expect(submit.spanContext().traceId).not.toBe(pageTrace);
    expect(Sentry.spanToJSON(submit).parent_span_id).toBeUndefined();
    const fetch = Sentry.startInactiveSpan({ name: 'fetch' });
    expect(Sentry.spanToJSON(fetch).parent_span_id).toBe(
        submit.spanContext().spanId,
    );
    fetch.end();

    fire('turbo:visit');
    const visit = Sentry.getActiveSpan();
    expect(Sentry.spanToJSON(submit).end_timestamp).toBeDefined();
    expect(visit.spanContext().traceId).not.toBe(pageTrace);
    expect(visit.spanContext().traceId).not.toBe(submit.spanContext().traceId);

    document.querySelector('meta[name="loupe-route"]').content =
        'app_card_show';
    fire('turbo:load');
    await settle();

    const json = Sentry.spanToJSON(visit);
    expect(json.end_timestamp).toBeDefined();
    expect(json.name).toBe('app_card_show');
    expect(json.attributes['loupe.tab_age_ms']).toEqual(expect.any(Number));
    expect(Sentry.getActiveSpan()).toBe(pageLoad);
});

it('sends a live change as an inactive span in its own trace', async () => {
    const active = Sentry.getActiveSpan();
    traceLive('board.card_changed', now(), Promise.resolve());
    await settle();
    expect(Sentry.getActiveSpan()).toBe(active);
    await Sentry.flush(2000);

    const live = bodies
        .flatMap((body) => body.split('\n'))
        .map((line) => JSON.parse(line))
        .find((item) => item.transaction === 'board.card_changed');
    expect(live.contexts.trace.op).toBe('ui.live');
    expect(live.contexts.trace.parent_span_id).toBeUndefined();
    expect(live.contexts.trace.trace_id).not.toBe(pageTrace);
    expect(live.contexts.trace.data['loupe.tab_age_ms']).toEqual(
        expect.any(Number),
    );
});
