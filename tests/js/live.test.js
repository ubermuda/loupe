/** @vitest-environment jsdom */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { emit, on, originId, reset, status } from '../../assets/lib/live.js';

const HUB = 'https://hub.test/.well-known/mercure';
const BOARD_TOPIC = 'https://app.test/projects/1/board';
const BOARD = 'board.columns_changed';
const INBOX = 'inbox.open_count_changed';

class FakeEventSource {
    static instances = [];

    constructor(url) {
        this.url = new URL(url);
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
}

function renderPage(topics, hub = HUB) {
    document.getElementById('mercure-subscriptions')?.remove();
    const form = document.createElement('form');
    form.id = 'mercure-subscriptions';
    form.action = '/mercure/authorize';
    if (hub !== null) {
        form.dataset.hub = hub;
    }
    topics.forEach((topic) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'authorize_mercure_topics_form[topics][]';
        input.value = topic;
        input.setAttribute('data-mercure-topic', '');
        form.append(input);
    });
    document.body.append(form);
}

function latest() {
    return FakeEventSource.instances.at(-1);
}

function message(data) {
    return { data: JSON.stringify(data), lastEventId: '' };
}

function beforeFetch(url, headers = {}) {
    const event = new CustomEvent('turbo:before-fetch-request', {
        bubbles: true,
        cancelable: true,
        detail: {
            url: new URL(url, window.location.href),
            fetchOptions: { headers },
        },
    });
    document.dispatchEvent(event);
    return headers;
}

beforeEach(() => {
    vi.useFakeTimers();
    FakeEventSource.instances = [];
    vi.stubGlobal('EventSource', FakeEventSource);
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ topics: [BOARD_TOPIC] }),
            }),
        ),
    );
    renderPage([BOARD_TOPIC]);
});

afterEach(() => {
    reset();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('on', () => {
    it('marks a remote message from another tab as not own', () => {
        const handler = vi.fn();
        on(BOARD, handler);
        vi.runOnlyPendingTimers();

        latest().emit('message', message({ type: BOARD, origin: 'other' }));
        latest().emit('message', message({ type: BOARD, origin: null }));

        expect(handler).toHaveBeenNthCalledWith(1, {
            type: BOARD,
            origin: 'other',
            local: false,
            own: false,
        });
        expect(handler).toHaveBeenNthCalledWith(2, {
            type: BOARD,
            origin: null,
            local: false,
            own: false,
        });
    });

    it('marks a remote message from this tab as own', () => {
        const handler = vi.fn();
        on([BOARD, INBOX], handler);
        vi.runOnlyPendingTimers();

        latest().emit('message', message({ type: INBOX, origin: originId() }));

        expect(handler).toHaveBeenCalledWith({
            type: INBOX,
            origin: originId(),
            local: false,
            own: true,
        });
    });

    it('runs onReconnect on each open after the first one', async () => {
        const onReconnect = vi.fn();
        on(BOARD, vi.fn(), { onReconnect });
        vi.runOnlyPendingTimers();

        latest().emit('open');
        expect(onReconnect).not.toHaveBeenCalled();

        latest().emit('error');
        await vi.advanceTimersByTimeAsync(1000);
        latest().emit('open');
        expect(onReconnect).toHaveBeenCalledOnce();
    });

    it('stops both remote and local delivery once unsubscribed', () => {
        const handler = vi.fn();
        const unsubscribe = on(BOARD, handler);
        vi.runOnlyPendingTimers();
        const source = latest();

        unsubscribe();
        emit(BOARD, {});
        source.emit('message', message({ type: BOARD, origin: null }));

        expect(handler).not.toHaveBeenCalled();
    });
});

describe('emit', () => {
    it('delivers at once to the local handlers of that type only', () => {
        const board = vi.fn();
        const inbox = vi.fn();
        on(BOARD, board);
        on(INBOX, inbox);

        emit(BOARD, { cardId: '7' });

        expect(board).toHaveBeenCalledWith({
            cardId: '7',
            type: BOARD,
            local: true,
            own: true,
        });
        expect(inbox).not.toHaveBeenCalled();
    });
});

describe('status', () => {
    it('reports off on a page with no hub', () => {
        renderPage([BOARD_TOPIC], null);
        const listener = vi.fn();
        status(listener);

        expect(listener).toHaveBeenCalledExactlyOnceWith('off');
    });

    it('reports off on a page with no topics', () => {
        renderPage([]);
        const listener = vi.fn();
        status(listener);

        expect(listener).toHaveBeenCalledExactlyOnceWith('off');
    });

    it('reports live, then paused only after five seconds down', async () => {
        const listener = vi.fn();
        on(BOARD, vi.fn());
        status(listener);
        vi.runOnlyPendingTimers();
        latest().emit('open');
        expect(listener.mock.calls).toEqual([['live']]);

        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
        latest().emit('error');
        await vi.advanceTimersByTimeAsync(4999);
        expect(listener.mock.calls).toEqual([['live']]);

        await vi.advanceTimersByTimeAsync(1);
        expect(listener.mock.calls).toEqual([['live'], ['paused']]);
    });

    it('stays live when the connection reopens within the grace time', async () => {
        const listener = vi.fn();
        on(BOARD, vi.fn());
        status(listener);
        vi.runOnlyPendingTimers();
        latest().emit('open');

        latest().emit('error');
        await vi.advanceTimersByTimeAsync(1000);
        latest().emit('open');
        await vi.advanceTimersByTimeAsync(10000);

        expect(listener.mock.calls).toEqual([['live']]);
    });

    it('reports live again when the connection reopens after a pause', async () => {
        const listener = vi.fn();
        on(BOARD, vi.fn());
        status(listener);
        vi.runOnlyPendingTimers();
        latest().emit('open');
        latest().emit('error');
        await vi.advanceTimersByTimeAsync(1000);
        latest().emit('error');
        await vi.advanceTimersByTimeAsync(4000);
        expect(listener.mock.calls).toEqual([['live'], ['paused']]);

        await vi.advanceTimersByTimeAsync(2000);
        latest().emit('open');

        expect(listener.mock.calls).toEqual([['live'], ['paused'], ['live']]);
    });

    it('follows a Turbo visit to a page with no hub', () => {
        const listener = vi.fn();
        status(listener);

        document.getElementById('mercure-subscriptions').remove();
        document.dispatchEvent(new Event('turbo:load'));

        expect(listener.mock.calls).toEqual([['live'], ['off']]);
    });

    it('stops calling a listener once unsubscribed', async () => {
        const listener = vi.fn();
        on(BOARD, vi.fn());
        const unsubscribe = status(listener);
        vi.runOnlyPendingTimers();
        unsubscribe();

        latest().emit('error');
        await vi.advanceTimersByTimeAsync(10000);

        expect(listener).toHaveBeenCalledExactlyOnceWith('live');
    });
});

describe('originId', () => {
    it('stays the same for the page', () => {
        expect(originId()).toMatch(/^[-0-9a-z]{16,}$/);
        expect(originId()).toBe(originId());
    });

    it('falls back when crypto.randomUUID is missing', () => {
        vi.stubGlobal('crypto', {});
        reset();

        expect(originId()).toMatch(/^[-0-9a-z]{16,}$/);
    });
});

describe('the fetch header hook', () => {
    it('stamps a same-origin Turbo request with the origin id', () => {
        const headers = beforeFetch('/projects/1/board', {
            Accept: 'text/html',
        });

        expect(headers).toEqual({
            Accept: 'text/html',
            'X-Loupe-Origin': originId(),
        });
    });

    it('leaves a cross-origin request alone', () => {
        const headers = beforeFetch('https://elsewhere.test/page');

        expect(headers).toEqual({});
    });
});
