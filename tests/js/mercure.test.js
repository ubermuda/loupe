import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reset, subscribe } from '../../assets/lib/mercure.js';

const HUB = 'https://hub.test/.well-known/mercure';
const TYPE = 'board.columns_changed';

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

function message(type, lastEventId = '') {
    return { data: JSON.stringify({ type }), lastEventId };
}

function open(topic, options = {}) {
    return subscribe(topic, { hub: HUB, types: [TYPE], ...options });
}

function latest() {
    return FakeEventSource.instances.at(-1);
}

describe('subscribe', () => {
    let fetch;

    beforeEach(() => {
        vi.useFakeTimers();
        FakeEventSource.instances = [];
        fetch = vi.fn(() => Promise.resolve());
        vi.stubGlobal('EventSource', FakeEventSource);
        vi.stubGlobal('fetch', fetch);
        vi.stubGlobal('window', { location: { href: 'https://app.test/' } });
    });

    afterEach(() => {
        reset();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('opens one connection for two subscriptions', () => {
        open('https://app.test/a');
        open('https://app.test/b');
        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(1);
        expect(latest().topics()).toEqual([
            'https://app.test/a',
            'https://app.test/b',
        ]);
        expect(latest().options).toEqual({ withCredentials: true });
    });

    it('opens once for several subscriptions in one tick', () => {
        open('https://app.test/a');
        open('https://app.test/b');
        open('https://app.test/c');
        expect(FakeEventSource.instances).toHaveLength(0);

        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(1);
    });

    it('reopens when the set of topics changes', () => {
        open('https://app.test/a');
        vi.runOnlyPendingTimers();
        const first = latest();

        const unsubscribeB = open('https://app.test/b');
        vi.runOnlyPendingTimers();

        expect(first.closed).toBe(true);
        expect(FakeEventSource.instances).toHaveLength(2);
        expect(latest().topics()).toEqual([
            'https://app.test/a',
            'https://app.test/b',
        ]);

        unsubscribeB();
        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(3);
        expect(latest().topics()).toEqual(['https://app.test/a']);
    });

    it('keeps the connection when a subscription leaves and returns in one tick', () => {
        const unsubscribe = open('https://app.test/a');
        vi.runOnlyPendingTimers();
        latest().emit('open');

        unsubscribe();
        const onOpen = vi.fn();
        open('https://app.test/a', { onOpen });
        vi.runOnlyPendingTimers();

        expect(FakeEventSource.instances).toHaveLength(1);
        expect(latest().closed).toBe(false);
        expect(onOpen).toHaveBeenCalledOnce();
    });

    it('dispatches a message to the subscriptions for its type only', () => {
        const board = vi.fn();
        const other = vi.fn();
        open('https://app.test/a', { onMessage: board });
        open('https://app.test/b', { types: ['other'], onMessage: other });
        vi.runOnlyPendingTimers();

        latest().emit('message', message(TYPE));

        expect(board).toHaveBeenCalledWith({ type: TYPE });
        expect(other).not.toHaveBeenCalled();

        latest().emit('message', message('other'));

        expect(board).toHaveBeenCalledOnce();
        expect(other).toHaveBeenCalledOnce();
    });

    it('closes the connection on the last unsubscribe', () => {
        const unsubscribeA = open('https://app.test/a');
        const unsubscribeB = open('https://app.test/b');
        vi.runOnlyPendingTimers();
        const source = latest();

        unsubscribeA();
        unsubscribeB();
        vi.runOnlyPendingTimers();

        expect(source.closed).toBe(true);
        expect(FakeEventSource.instances).toHaveLength(1);
    });

    it('renews every authorization, then reconnects with the last event id', async () => {
        const onError = vi.fn();
        const onOpen = vi.fn();
        open('https://app.test/a', {
            authorize: '/a/authorize',
            onError,
            onOpen,
        });
        open('https://app.test/b', { authorize: '/b/authorize' });
        vi.runOnlyPendingTimers();
        const first = latest();
        first.emit('open');
        first.emit('message', message(TYPE, 'urn:uuid:42'));

        first.emit('error');

        expect(first.closed).toBe(true);
        expect(onError).toHaveBeenCalledOnce();
        expect(fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1000);

        expect(fetch.mock.calls.map(([url]) => url)).toEqual([
            '/a/authorize',
            '/b/authorize',
        ]);
        expect(FakeEventSource.instances).toHaveLength(2);
        expect(latest().url.searchParams.get('lastEventID')).toBe(
            'urn:uuid:42',
        );

        latest().emit('open');

        expect(onOpen).toHaveBeenCalledTimes(2);
    });

    it('doubles the retry delay up to sixty seconds', async () => {
        open('https://app.test/a');
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
        const unsubscribe = open('https://app.test/a', {
            authorize: '/a/authorize',
        });
        vi.runOnlyPendingTimers();
        latest().emit('error');

        unsubscribe();
        vi.runOnlyPendingTimers();
        await vi.advanceTimersByTimeAsync(60000);

        expect(FakeEventSource.instances).toHaveLength(1);
    });
});
