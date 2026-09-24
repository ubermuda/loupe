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

const { default: BoardRefreshController } =
    await import('../../assets/controllers/board_refresh_controller.js');

let application;

beforeEach(() => {
    mercure.subscriptions = [];
    application = Application.start();
    application.register('board-refresh', BoardRefreshController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await Promise.resolve();
    application.stop();
});

async function mount({ src = null } = {}) {
    document.body.innerHTML = `<div data-controller="board-refresh" data-board-refresh-board-value="/projects/1/board">
        <turbo-frame id="board-frame" data-board-refresh-target="frame"${src ? ` src="${src}"` : ''}></turbo-frame>
    </div>`;
    const frame = document.querySelector('turbo-frame');
    frame.reload = vi.fn();
    await Promise.resolve();
    return frame;
}

function subscription() {
    expect(mercure.subscriptions).toHaveLength(1);
    return mercure.subscriptions[0];
}

it('listens for a column change and for a worker run change', async () => {
    await mount();
    expect(subscription().types).toEqual([
        'board.columns_changed',
        'worker_run.changed',
    ]);
});

it('gives a frame with no src the board url on a signal', async () => {
    const frame = await mount();
    subscription().handler({ type: 'worker_run.changed' });
    expect(frame.src).toBe('/projects/1/board');
    expect(frame.reload).not.toHaveBeenCalled();
});

it('reloads a frame that has a src on a signal', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    subscription().handler({ type: 'worker_run.changed' });
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads after a reconnect, not after the first open', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    subscription().options.onOpen();
    expect(frame.reload).not.toHaveBeenCalled();
    subscription().options.onOpen();
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('stops listening on disconnect', async () => {
    await mount();
    const listening = subscription();
    document.body.replaceChildren();
    await Promise.resolve();
    expect(listening.removed).toBe(true);
});
