/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);
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
    live.subscriptions = [];
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

async function mount({ url = '', src = null, events = null } = {}) {
    document.body.innerHTML = `<div data-controller="worker-run-refresh"${url ? ` data-worker-run-refresh-url-value="${url}"` : ''}${events ? ` data-worker-run-refresh-events-value='${JSON.stringify(events)}'` : ''}>
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
    expect(live.subscriptions).toHaveLength(1);
    return live.subscriptions[0];
}

async function signal() {
    subscription().handler({ type: 'worker_run.changed' });
}

it('listens for the worker run signal only', async () => {
    await mount();
    expect(subscription().types).toEqual(['worker_run.changed']);
});

it('reloads on a card change when the events value lists it', async () => {
    const frame = await mount({
        src: '/projects/1/worker-runs',
        events: ['worker_run.changed', 'board.card_changed'],
    });
    expect(subscription().types).toEqual([
        'worker_run.changed',
        'board.card_changed',
    ]);
    subscription().handler({ type: 'board.card_changed' });
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('does not listen for a card change without the events value', async () => {
    await mount({ src: '/projects/1/worker-runs' });
    expect(subscription().types).not.toContain('board.card_changed');
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

it('reloads after a reconnect', async () => {
    const frame = await mount({ src: '/projects/1/worker-runs' });
    subscription().options.onReconnect();
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

it('reloads the whole page when its own element is the target', async () => {
    window.Turbo = { visit: vi.fn() };
    document.body.innerHTML = `<div data-controller="worker-run-refresh" data-worker-run-refresh-target="frame" data-worker-run-refresh-whole-value="true"
        data-worker-run-refresh-events-value='["worker_run.changed","board.card_changed","inbox.open_count_changed"]'></div>`;
    await vi.advanceTimersByTimeAsync(0);
    expect(subscription().types).toEqual([
        'worker_run.changed',
        'board.card_changed',
        'inbox.open_count_changed',
    ]);

    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(window.Turbo.visit).toHaveBeenCalledWith(window.location.href, {
        action: 'replace',
    });
    delete window.Turbo;
});

async function mountForProject(project) {
    window.Turbo = { visit: vi.fn() };
    const projectValue =
        project === undefined
            ? ''
            : ` data-worker-run-refresh-project-value="${project}"`;
    document.body.innerHTML = `<div data-controller="worker-run-refresh" data-worker-run-refresh-target="frame" data-worker-run-refresh-whole-value="true"${projectValue}></div>`;
    await vi.advanceTimersByTimeAsync(0);
}

async function changeOf(projectId) {
    subscription().handler({ type: 'inbox.open_count_changed', projectId });
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
}

it('reloads for a change that names its project', async () => {
    await mountForProject('project-1');
    await changeOf('project-1');
    expect(window.Turbo.visit).toHaveBeenCalledOnce();
    delete window.Turbo;
});

it('ignores a change that names another project', async () => {
    await mountForProject('project-1');
    await changeOf('project-2');
    expect(window.Turbo.visit).not.toHaveBeenCalled();
    delete window.Turbo;
});

it('reloads for a change that names no project', async () => {
    await mountForProject('project-1');
    await signal();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(window.Turbo.visit).toHaveBeenCalledOnce();
    delete window.Turbo;
});

it('reloads for any project when it has no project value', async () => {
    await mountForProject(undefined);
    await changeOf('project-2');
    expect(window.Turbo.visit).toHaveBeenCalledOnce();
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
