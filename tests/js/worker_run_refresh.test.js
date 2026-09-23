/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const mercure = vi.hoisted(() => ({ subscriptions: [] }));

vi.mock('../../assets/lib/mercure.js', () => ({
    subscribe: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        mercure.subscriptions.push(subscription);
        return () => {
            subscription.removed = true;
        };
    },
}));

const { default: WorkerRunRefreshController, DEBOUNCE_MILLISECONDS } =
    await import('../../assets/controllers/worker_run_refresh_controller.js');

let application;

beforeEach(() => {
    vi.useFakeTimers();
    mercure.subscriptions = [];
    window.history.replaceState(
        {},
        '',
        '/projects/1/worker-runs?outcome=failed',
    );
    application = Application.start();
    application.register('worker-run-refresh', WorkerRunRefreshController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
});

async function mount({ url = '', src = null } = {}) {
    document.body.innerHTML = `<div data-controller="worker-run-refresh"${url ? ` data-worker-run-refresh-url-value="${url}"` : ''}>
        <turbo-frame id="runs" data-worker-run-refresh-target="frame"${src ? ` src="${src}"` : ''}>
            <dialog id="drawer"></dialog>
        </turbo-frame>
    </div>`;
    const frame = document.querySelector('turbo-frame');
    frame.reload = vi.fn();
    await vi.advanceTimersByTimeAsync(0);
    return frame;
}

function subscription() {
    expect(mercure.subscriptions).toHaveLength(1);
    return mercure.subscriptions[0];
}

async function signal() {
    subscription().handler({ type: 'worker_run.changed' });
}

it('listens for the worker run signal only', async () => {
    await mount();
    expect(subscription().types).toBe('worker_run.changed');
});

it('gives a frame with no src the current page on the first signal', async () => {
    const frame = await mount();
    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(frame.getAttribute('src')).toBe(window.location.href);
    expect(frame.reload).not.toHaveBeenCalled();
});

it('gives a frame with no src its fragment url when one is set', async () => {
    const frame = await mount({ url: '/projects/1/worker-runs/card/2' });
    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(frame.getAttribute('src')).toBe('/projects/1/worker-runs/card/2');
});

it('reloads once for a burst of signals', async () => {
    const frame = await mount({ src: '/projects/1/worker-runs' });
    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS - 1);
    await signal();
    await signal();
    expect(frame.reload).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads after a reconnect but not on the first open', async () => {
    const frame = await mount({ src: '/projects/1/worker-runs' });
    subscription().options.onOpen();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(frame.reload).not.toHaveBeenCalled();

    subscription().options.onOpen();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('holds the reload while a drawer is open and reloads when it closes', async () => {
    const frame = await mount({ src: '/projects/1/worker-runs' });
    const drawer = document.getElementById('drawer');
    drawer.setAttribute('open', '');

    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(frame.reload).not.toHaveBeenCalled();

    drawer.removeAttribute('open');
    drawer.dispatchEvent(new Event('close'));
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('does not reload when a drawer closes with no signal held', async () => {
    const frame = await mount({ src: '/projects/1/worker-runs' });
    const drawer = document.getElementById('drawer');
    drawer.dispatchEvent(new Event('close'));
    expect(frame.reload).not.toHaveBeenCalled();
});

it('reloads the count frames outside it with the list', async () => {
    document.body.innerHTML = `<turbo-frame id="shown" src="/projects/1/worker-runs"></turbo-frame>
        <div data-controller="worker-run-refresh" data-worker-run-refresh-frames-value='["shown","missing"]'>
            <turbo-frame id="runs" data-worker-run-refresh-target="frame" src="/projects/1/worker-runs"></turbo-frame>
        </div>`;
    const frames = [...document.querySelectorAll('turbo-frame')];
    frames.forEach((frame) => {
        frame.reload = vi.fn();
    });
    await vi.advanceTimersByTimeAsync(0);

    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    frames.forEach((frame) => expect(frame.reload).toHaveBeenCalledOnce());
});

it('reloads the whole page when it has no filters to keep', async () => {
    window.Turbo = { visit: vi.fn() };
    document.body.innerHTML = `<div data-controller="worker-run-refresh" data-worker-run-refresh-whole-value="true">
        <turbo-frame id="runs" data-worker-run-refresh-target="frame" src="/projects/1/worker-runs"></turbo-frame>
    </div>`;
    const frame = document.querySelector('turbo-frame');
    frame.reload = vi.fn();
    await vi.advanceTimersByTimeAsync(0);

    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(window.Turbo.visit).toHaveBeenCalledWith(window.location.href, {
        action: 'replace',
    });
    expect(frame.reload).not.toHaveBeenCalled();
    delete window.Turbo;
});

it('stops listening and drops a pending reload on disconnect', async () => {
    const frame = await mount({ src: '/projects/1/worker-runs' });
    const listening = subscription();
    await signal();
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(listening.removed).toBe(true);
    expect(frame.reload).not.toHaveBeenCalled();
});
