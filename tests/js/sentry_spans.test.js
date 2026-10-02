/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    enableSpans,
    now,
    reset,
    traceLive,
} from '../../assets/lib/sentry_spans.js';

const STREAM = 'text/vnd.turbo-stream.html; charset=utf-8';

function fakeSentry() {
    const spans = [];

    return {
        spans,
        startNewTrace: vi.fn((callback) => callback()),
        startInactiveSpan: vi.fn((options) => {
            const span = { options, end: vi.fn(), setStatus: vi.fn() };
            spans.push(span);
            return span;
        }),
        setActiveSpanInBrowser: vi.fn(),
        updateSpanName: vi.fn(),
        getActiveSpan: vi.fn(),
    };
}

function setRoute(route) {
    let meta = document.querySelector('meta[name="loupe-route"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'loupe-route';
        document.head.append(meta);
    }
    meta.content = route;
}

function fire(type, detail = {}, target = document) {
    target.dispatchEvent(new CustomEvent(type, { detail, bubbles: true }));
}

function response(contentType) {
    return {
        contentType,
        isHTML: /^text\/([^\s;,]+\b)?html/.test(contentType ?? ''),
    };
}

// A zero timeout set inside a fake frame runs 1 ms later.
function paint() {
    vi.advanceTimersToNextFrame();
    vi.advanceTimersByTime(1);
}

describe('sentry spans', () => {
    let sentry;

    beforeEach(() => {
        vi.useFakeTimers({
            toFake: [
                'setTimeout',
                'clearTimeout',
                'requestAnimationFrame',
                'cancelAnimationFrame',
                'performance',
                'Date',
            ],
        });
        document.head.innerHTML = '';
        setRoute('app_board_show');
        sentry = fakeSentry();
    });

    afterEach(() => {
        reset();
        vi.restoreAllMocks();
        vi.useRealTimers();
        delete performance.memory;
    });

    it('does nothing before the spans are enabled', async () => {
        fire('turbo:submit-start');
        fire('turbo:visit');
        traceLive('board.card_changed', now(), Promise.resolve());
        await vi.runAllTimersAsync();

        expect(sentry.startInactiveSpan).not.toHaveBeenCalled();
    });

    it('starts an active submit span in its own trace, named after the route', () => {
        enableSpans(sentry);
        vi.advanceTimersByTime(1500);

        fire('turbo:submit-start');

        expect(sentry.startNewTrace).toHaveBeenCalledTimes(1);
        const [span] = sentry.spans;
        expect(span.options).toEqual({
            op: 'ui.turbo.submit',
            name: 'app_board_show',
            attributes: { 'loupe.tab_age_ms': 1500 },
        });
        expect(sentry.setActiveSpanInBrowser).toHaveBeenCalledWith(span);
    });

    it('names a span after its op when the page has no route', () => {
        setRoute('');
        enableSpans(sentry);

        fire('turbo:submit-start');

        expect(sentry.spans[0].options.name).toBe('ui.turbo.submit');
    });

    it('records the heap size when the browser reports it', () => {
        performance.memory = { usedJSHeapSize: 52 * 1048576 + 1000 };
        enableSpans(sentry);

        fire('turbo:visit');

        expect(sentry.spans[0].options.attributes['loupe.heap_mb']).toBe(52);
    });

    it('ends a stream submit only after a render signal and a paint', () => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        fire('turbo:submit-end', { fetchResponse: response(STREAM) });
        paint();

        const [span] = sentry.spans;
        expect(span.end).not.toHaveBeenCalled();

        fire('turbo:before-stream-render');
        expect(span.end).not.toHaveBeenCalled();
        paint();

        expect(span.end).toHaveBeenCalledTimes(1);
    });

    it.each(['turbo:render', 'turbo:frame-render'])(
        'ends an HTML submit after %s and a paint',
        (signal) => {
            enableSpans(sentry);
            fire('turbo:submit-start');
            fire('turbo:submit-end', {
                fetchResponse: response('text/html; charset=utf-8'),
            });
            fire(signal);
            paint();

            expect(sentry.spans[0].end).toHaveBeenCalledTimes(1);
        },
    );

    it.each([
        ['no response', undefined],
        ['a JSON response', response('application/json')],
        ['a response with no type', response(null)],
    ])('ends a submit with %s after a paint', (_label, fetchResponse) => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        fire('turbo:submit-end', { fetchResponse });

        const [span] = sentry.spans;
        expect(span.end).not.toHaveBeenCalled();
        paint();

        expect(span.end).toHaveBeenCalledTimes(1);
    });

    it('waits two timeouts instead of a frame in a hidden document', () => {
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
        const frame = vi.spyOn(window, 'requestAnimationFrame');
        enableSpans(sentry);
        fire('turbo:submit-start');
        fire('turbo:submit-end', {});

        vi.advanceTimersByTime(1);

        expect(sentry.spans[0].end).toHaveBeenCalledTimes(1);
        expect(frame).not.toHaveBeenCalled();
    });

    it('ends a submit at once when a visit starts', () => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        fire('turbo:submit-end', {
            fetchResponse: response('text/html; charset=utf-8'),
        });

        fire('turbo:visit');

        const [submit, visit] = sentry.spans;
        expect(submit.end).toHaveBeenCalledTimes(1);
        expect(submit.end).toHaveBeenCalledWith();
        expect(visit.options.op).toBe('navigation');
        expect(visit.end).not.toHaveBeenCalled();
    });

    it('leaves a visit span alone when its submit ends late', () => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        fire('turbo:visit');
        fire('turbo:submit-end', {});
        paint();

        expect(sentry.spans[1].end).not.toHaveBeenCalled();
    });

    it('renames a visit after the new route at load, then ends it after a paint', () => {
        enableSpans(sentry);
        fire('turbo:visit');
        const [visit] = sentry.spans;
        expect(visit.options.name).toBe('app_board_show');

        fire('turbo:render');
        paint();
        expect(visit.end).not.toHaveBeenCalled();

        setRoute('app_card_show');
        fire('turbo:load');
        expect(sentry.updateSpanName).toHaveBeenCalledWith(
            visit,
            'app_card_show',
        );
        expect(visit.end).not.toHaveBeenCalled();
        paint();

        expect(visit.end).toHaveBeenCalledTimes(1);
    });

    it('ignores the load of the first page', () => {
        enableSpans(sentry);

        fire('turbo:load');
        paint();

        expect(sentry.updateSpanName).not.toHaveBeenCalled();
        expect(sentry.startInactiveSpan).not.toHaveBeenCalled();
    });

    it('ends a span with no end signal at 10 s as past its deadline', () => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        vi.advanceTimersByTime(400);
        fire('turbo:submit-end', { fetchResponse: response(STREAM) });

        vi.advanceTimersByTime(9599);
        const [span] = sentry.spans;
        expect(span.end).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);

        expect(span.setStatus).toHaveBeenCalledWith({
            code: 2,
            message: 'deadline_exceeded',
        });
        expect(span.end).toHaveBeenCalledTimes(1);
        expect(span.end).toHaveBeenCalledWith();
    });

    it('sets no status on a span that ends in time', () => {
        enableSpans(sentry);
        fire('turbo:visit');
        fire('turbo:load');
        paint();
        vi.advanceTimersByTime(20000);

        expect(sentry.spans[0].setStatus).not.toHaveBeenCalled();
    });

    it('does not end a span twice after the cap', () => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        fire('turbo:submit-end', {});
        paint();
        vi.advanceTimersByTime(20000);

        expect(sentry.spans[0].end).toHaveBeenCalledTimes(1);
    });

    it('ends a visit at once when the visit fetch fails', () => {
        enableSpans(sentry);
        fire('turbo:visit', {}, document.documentElement);

        fire('turbo:fetch-request-error', {}, document.documentElement);

        expect(sentry.spans[0].end).toHaveBeenCalledTimes(1);
        paint();
        vi.advanceTimersByTime(20000);
        expect(sentry.spans[0].end).toHaveBeenCalledTimes(1);
    });

    it('ends a submit at once when its form fetch fails', () => {
        const form = document.createElement('form');
        document.body.append(form);
        enableSpans(sentry);
        fire('turbo:submit-start', {}, form);

        fire('turbo:fetch-request-error', {}, document.documentElement);
        expect(sentry.spans[0].end).not.toHaveBeenCalled();
        fire('turbo:fetch-request-error', {}, form);

        expect(sentry.spans[0].end).toHaveBeenCalledTimes(1);
        form.remove();
    });

    it('keeps the open span when another fetch fails', () => {
        const link = document.createElement('a');
        document.body.append(link);
        enableSpans(sentry);
        fire('turbo:visit', {}, document.documentElement);

        fire('turbo:fetch-request-error', {}, link);

        expect(sentry.spans[0].end).not.toHaveBeenCalled();
        link.remove();
    });

    it('leaves a recording active span in place', () => {
        sentry.getActiveSpan.mockReturnValue({ isRecording: () => true });
        enableSpans(sentry);

        fire('turbo:submit-start');

        expect(sentry.spans).toHaveLength(1);
        expect(sentry.setActiveSpanInBrowser).not.toHaveBeenCalled();
    });

    it('replaces an active span that has ended', () => {
        sentry.getActiveSpan.mockReturnValue({ isRecording: () => false });
        enableSpans(sentry);

        fire('turbo:submit-start');

        expect(sentry.setActiveSpanInBrowser).toHaveBeenCalledWith(
            sentry.spans[0],
        );
    });

    it('keeps one active span at a time', () => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        fire('turbo:submit-start');
        fire('turbo:visit');

        const [first, second, third] = sentry.spans;
        expect(first.end).toHaveBeenCalledTimes(1);
        expect(second.end).toHaveBeenCalledTimes(1);
        expect(third.end).not.toHaveBeenCalled();
        expect(first.end.mock.invocationCallOrder[0]).toBeLessThan(
            sentry.startInactiveSpan.mock.invocationCallOrder[1],
        );
        expect(sentry.setActiveSpanInBrowser).toHaveBeenCalledTimes(3);
    });

    it('installs its listeners once', () => {
        enableSpans(sentry);
        enableSpans(sentry);

        fire('turbo:submit-start');

        expect(sentry.startInactiveSpan).toHaveBeenCalledTimes(1);
    });

    it('records a live change after it settles and the page paints', async () => {
        enableSpans(sentry);
        vi.advanceTimersByTime(2000);
        const arrival = now();
        let settle;
        traceLive(
            'board.card_changed',
            arrival,
            new Promise((resolve) => (settle = resolve)),
        );
        vi.advanceTimersByTime(300);
        await Promise.resolve();
        expect(sentry.startInactiveSpan).not.toHaveBeenCalled();

        settle();
        await Promise.resolve();
        await Promise.resolve();
        paint();

        expect(sentry.startNewTrace).toHaveBeenCalledTimes(1);
        const [span] = sentry.spans;
        expect(span.options).toEqual({
            op: 'ui.live',
            name: 'board.card_changed',
            startTime: arrival,
            attributes: { 'loupe.tab_age_ms': 2000 },
        });
        expect(span.end).toHaveBeenCalledTimes(1);
        expect(span.end).toHaveBeenCalledWith();
        expect(sentry.setActiveSpanInBrowser).not.toHaveBeenCalled();
    });

    it('records a live change that fails and passes the failure on', async () => {
        enableSpans(sentry);
        const traced = traceLive(
            'card.moved',
            now(),
            Promise.reject(new Error('x')),
        );

        await expect(traced).rejects.toThrow('x');
        paint();

        expect(sentry.spans[0].end).toHaveBeenCalledTimes(1);
    });

    it('records nothing for a live change that returns no promise', async () => {
        enableSpans(sentry);
        traceLive('card.moved', now(), undefined);
        traceLive('card.moved', now(), 42);
        await vi.runAllTimersAsync();

        expect(sentry.startInactiveSpan).not.toHaveBeenCalled();
    });

    it('leaves an open interaction span alone when a live change records', async () => {
        enableSpans(sentry);
        fire('turbo:submit-start');
        traceLive('card.moved', now(), Promise.resolve());
        await Promise.resolve();
        await Promise.resolve();
        paint();

        expect(sentry.spans[0].end).not.toHaveBeenCalled();
    });
});
