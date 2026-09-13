/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reset, subscribe } from '../../assets/lib/mercure.js';

const HUB = 'https://hub.test/.well-known/mercure';
const BOARD_TOPIC = 'https://app.test/projects/1/board';
const REVIEW_TOPIC = 'https://app.test/documents/2/review';
const BOARD = 'board.columns_changed';
const REVIEW = 'review.comment_added';

class FakeEventSource {
    static instances = [];

    constructor(url, options) {
        this.url = new URL(url);
        this.options = options;
        this.listeners = {};
        this.closed = false;
        FakeEventSource.instances.push(this);
    }

    addEventListener(name, listener) {
        (this.listeners[name] ??= []).push(listener);
    }

    close() {
        this.closed = true;
    }

    emit(name, event = {}) {
        (this.listeners[name] ?? []).forEach((listener) => listener(event));
    }

    topics() {
        return this.url.searchParams.getAll('topic');
    }
}

/** Renders the element the layout renders, as a Turbo visit would swap it in. */
function renderPage(topics, hub = HUB) {
    document.getElementById('mercure-subscriptions')?.remove();
    const form = document.createElement('form');
    form.id = 'mercure-subscriptions';
    form.method = 'post';
    form.action = '/mercure/authorize';
    form.dataset.hub = hub;
    form.hidden = true;
    topics.forEach((topic) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'authorize_mercure_topics_form[topics][]';
        input.value = topic;
        input.setAttribute('data-mercure-topic', '');
        form.append(input);
    });
    const token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'authorize_mercure_topics_form[_token]';
    token.value = 'csrf-token';
    token.setAttribute('data-controller', 'csrf-protection');
    form.append(token);
    document.body.append(form);
}

function message(type, lastEventId = '') {
    return { data: JSON.stringify({ type }), lastEventId };
}

function latest() {
    return FakeEventSource.instances.at(-1);
}

function allowing(topics) {
    return vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ topics }),
        }),
    );
}

describe('subscribe', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        FakeEventSource.instances = [];
        vi.stubGlobal('EventSource', FakeEventSource);
        vi.stubGlobal('fetch', allowing([BOARD_TOPIC, REVIEW_TOPIC]));
        renderPage([REVIEW_TOPIC, BOARD_TOPIC]);
    });

    afterEach(() => {
        reset();
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('opens one connection for handlers from two domains', () => {
        const board = vi.fn();
        const review = vi.fn();
        subscribe(BOARD, board);
        subscribe(REVIEW_TOPIC, [REVIEW], review);
        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(1);
        expect(latest().topics()).toEqual([REVIEW_TOPIC, BOARD_TOPIC].sort());
        expect(latest().options).toEqual({ withCredentials: true });

        latest().emit('message', message(BOARD));
        expect(board).toHaveBeenCalledWith({ type: BOARD });
        expect(review).not.toHaveBeenCalled();

        latest().emit('message', message(REVIEW));
        expect(board).toHaveBeenCalledOnce();
        expect(review).toHaveBeenCalledWith({ type: REVIEW });
    });

    it('opens once for several subscriptions in one tick', () => {
        subscribe(BOARD, vi.fn());
        subscribe(REVIEW, vi.fn());
        expect(FakeEventSource.instances).toHaveLength(0);

        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(1);
    });

    it('does nothing on a page with no subscriptions element', () => {
        document.body.innerHTML = '';
        const unsubscribe = subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(0);
        unsubscribe();
    });

    it('silences a topic-scoped handler whose topic the page lacks', () => {
        renderPage([BOARD_TOPIC]);
        const review = vi.fn();
        const onOpen = vi.fn();
        const onError = vi.fn();
        subscribe(REVIEW_TOPIC, [REVIEW], review, { onOpen, onError });
        subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();
        latest().emit('open');
        latest().emit('message', message(REVIEW));
        latest().emit('error');

        expect(review).not.toHaveBeenCalled();
        expect(onOpen).not.toHaveBeenCalled();
        expect(onError).not.toHaveBeenCalled();
    });

    it('reopens when a Turbo visit changes the topics', () => {
        subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();
        const first = latest();

        renderPage([BOARD_TOPIC]);
        document.dispatchEvent(new Event('turbo:load'));
        vi.runOnlyPendingTimers();

        expect(first.closed).toBe(true);
        expect(latest().topics()).toEqual([BOARD_TOPIC]);
    });

    it('keeps the connection when a subscription leaves and returns in one tick', () => {
        const unsubscribe = subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();
        latest().emit('open');

        unsubscribe();
        const onOpen = vi.fn();
        subscribe(BOARD, vi.fn(), { onOpen });
        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(1);
        expect(latest().closed).toBe(false);
        expect(onOpen).toHaveBeenCalledOnce();
    });

    it('closes the connection when nothing is subscribed', () => {
        const unsubscribeBoard = subscribe(BOARD, vi.fn());
        const unsubscribeReview = subscribe(REVIEW_TOPIC, [REVIEW], vi.fn());
        vi.runOnlyPendingTimers();
        const source = latest();

        unsubscribeBoard();
        unsubscribeReview();
        vi.runOnlyPendingTimers();

        expect(source.closed).toBe(true);
        expect(FakeEventSource.instances).toHaveLength(1);
    });

    it('renews the full topic set with a CSRF token, then reconnects with the last event id', async () => {
        const onError = vi.fn();
        const onOpen = vi.fn();
        subscribe(BOARD, vi.fn(), { onError, onOpen });
        subscribe(REVIEW_TOPIC, [REVIEW], vi.fn());
        vi.runOnlyPendingTimers();
        const first = latest();
        first.emit('open');
        first.emit('message', message(BOARD, 'urn:uuid:42'));

        first.emit('error');

        expect(first.closed).toBe(true);
        expect(onError).toHaveBeenCalledOnce();
        expect(fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1000);

        expect(fetch).toHaveBeenCalledOnce();
        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('http://localhost:3000/mercure/authorize');
        expect(init.method).toBe('POST');
        expect(
            init.body.getAll('authorize_mercure_topics_form[topics][]').sort(),
        ).toEqual([BOARD_TOPIC, REVIEW_TOPIC].sort());
        // The double-submit value replaces the same-origin sentinel.
        expect(init.body.get('authorize_mercure_topics_form[_token]')).toMatch(
            /^[-_/+a-zA-Z0-9]{24,}$/,
        );
        expect(FakeEventSource.instances).toHaveLength(2);
        expect(latest().url.searchParams.get('lastEventID')).toBe(
            'urn:uuid:42',
        );

        latest().emit('open');

        expect(onOpen).toHaveBeenCalledTimes(2);
    });

    it('drops the topics a renewal refuses before it reconnects', async () => {
        vi.stubGlobal('fetch', allowing([BOARD_TOPIC]));
        subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();

        latest().emit('error');
        await vi.advanceTimersByTimeAsync(1000);

        expect(latest().topics()).toEqual([BOARD_TOPIC]);
    });

    it('closes when a renewal refuses every topic', async () => {
        vi.stubGlobal('fetch', allowing([]));
        subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();

        latest().emit('error');
        await vi.advanceTimersByTimeAsync(60000);

        expect(FakeEventSource.instances).toHaveLength(1);
    });

    it('doubles the retry delay up to sixty seconds', async () => {
        subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();

        const delays = [];
        for (let attempt = 0; attempt < 8; attempt++) {
            const before = FakeEventSource.instances.length;
            latest().emit('error');
            let waited = 0;
            while (FakeEventSource.instances.length === before) {
                await vi.advanceTimersByTimeAsync(1000);
                waited += 1000;
            }
            delays.push(waited);
        }

        expect(delays).toEqual([
            1000, 2000, 4000, 8000, 16000, 32000, 60000, 60000,
        ]);
    });

    it('does not reconnect after the last unsubscribe during a retry', async () => {
        const unsubscribe = subscribe(BOARD, vi.fn());
        vi.runOnlyPendingTimers();
        latest().emit('error');

        unsubscribe();
        vi.runOnlyPendingTimers();
        await vi.advanceTimersByTimeAsync(60000);

        expect(FakeEventSource.instances).toHaveLength(1);
    });
});
