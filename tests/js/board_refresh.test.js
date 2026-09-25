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

const { default: BoardRefreshController } =
    await import('../../assets/controllers/board_refresh_controller.js');

let application;

beforeEach(() => {
    vi.useFakeTimers();
    live.subscriptions = [];
    application = Application.start();
    application.register('board-refresh', BoardRefreshController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await Promise.resolve();
    application.stop();
    vi.useRealTimers();
});

async function mount({ src = null, complete = false } = {}) {
    const attributes =
        (src ? ` src="${src}"` : '') + (complete ? ' complete' : '');
    document.body.innerHTML = `<div data-controller="board-refresh" data-board-refresh-board-value="/projects/1/board">
        <turbo-frame id="board-frame" refresh="morph" data-board-refresh-target="frame"${attributes}><div class="lp-board"></div></turbo-frame>
    </div>`;
    const frame = document.querySelector('turbo-frame');
    frame.calls = [];
    const setAttribute = frame.setAttribute.bind(frame);
    const removeAttribute = frame.removeAttribute.bind(frame);
    frame.setAttribute = (name, value) => {
        frame.calls.push(`set ${name}`);
        setAttribute(name, value);
    };
    frame.removeAttribute = (name) => {
        frame.calls.push(`remove ${name}`);
        removeAttribute(name);
    };
    frame.reload = vi.fn();
    await Promise.resolve();
    return frame;
}

function subscription() {
    expect(live.subscriptions).toHaveLength(1);
    return live.subscriptions[0];
}

it('listens for a column change and for a worker run change', async () => {
    await mount();
    expect(subscription().types).toEqual([
        'board.columns_changed',
        'worker_run.changed',
    ]);
});

it('gives the frame its src while disabled, so Turbo loads nothing until a reload', async () => {
    const frame = await mount();
    expect(frame.calls).toEqual([
        'set disabled',
        'set src',
        'set complete',
        'remove disabled',
    ]);
    expect(frame.getAttribute('src')).toBe('/projects/1/board');
    expect(frame.hasAttribute('complete')).toBe(true);
    expect(frame.hasAttribute('disabled')).toBe(false);
});

it('leaves a frame that is already complete alone', async () => {
    const frame = await mount({ src: '/projects/1/board', complete: true });
    expect(frame.calls).toEqual([]);
});

it('gives a frame with no src the board url on a signal', async () => {
    const frame = await mount();
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(300);
    expect(frame.getAttribute('src')).toBe('/projects/1/board');
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads a frame that has a src on a signal', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('coalesces a burst of signals into one reload', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(200);
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(200);
    subscription().handler({ type: 'board.columns_changed' });
    vi.advanceTimersByTime(299);
    expect(frame.reload).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads a steady stream of signals at least once per max wait', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const reloadTimes = [];
    frame.reload.mockImplementation(() => reloadTimes.push(Date.now()));
    const start = Date.now();
    for (let elapsed = 0; elapsed < 5000; elapsed += 100) {
        subscription().handler({ type: 'worker_run.changed' });
        vi.advanceTimersByTime(100);
    }
    const times = [start, ...reloadTimes, Date.now()];
    const gaps = times.slice(1).map((time, index) => time - times[index]);
    expect(reloadTimes.length).toBeGreaterThanOrEqual(2);
    expect(Math.max(...gaps)).toBeLessThanOrEqual(2000);
});

it('defers a reload while a drag runs, and reloads once it ends', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const board = frame.querySelector('.lp-board');
    board.classList.add('lp-board--dragging');
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    board.classList.remove('lp-board--dragging');
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('defers a reload while a dropped card move is pending', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const board = frame.querySelector('.lp-board');
    const card = document.createElement('div');
    card.dataset.boardDragTarget = 'card';
    board.append(card);
    board.classList.add('lp-board--dragging');
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(300);
    board.classList.remove('lp-board--dragging');
    card.setAttribute('aria-busy', 'true');
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    card.removeAttribute('aria-busy');
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('defers a reload while a column drag runs, and reloads once it ends', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const column = document.createElement('section');
    column.classList.add('lp-board__column--dragging');
    frame.querySelector('.lp-board').append(column);
    subscription().handler({ type: 'board.columns_changed' });
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    column.classList.remove('lp-board__column--dragging');
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('defers a reload while a column reorder is pending', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const form = document.createElement('form');
    form.dataset.boardColumnsTarget = 'form';
    form.setAttribute('aria-busy', 'true');
    frame.querySelector('.lp-board').append(form);
    subscription().handler({ type: 'board.columns_changed' });
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    form.removeAttribute('aria-busy');
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('defers a reload while a dialog is open, and reloads once it closes', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const dialog = document.createElement('dialog');
    dialog.setAttribute('open', '');
    frame.querySelector('.lp-board').append(dialog);
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    dialog.removeAttribute('open');
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('defers a reload while a column menu is open, and reloads once it hides', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const panel = document.createElement('div');
    panel.classList.add('lp-board__column-menu-panel');
    frame.querySelector('.lp-board').append(panel);
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    panel.hidden = true;
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('ignores a closed dialog and a hidden column menu', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const panel = document.createElement('div');
    panel.classList.add('lp-board__column-menu-panel');
    panel.hidden = true;
    panel.append(document.createElement('dialog'));
    frame.querySelector('.lp-board').append(panel);
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('ignores a busy form that is not a card move', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const form = document.createElement('form');
    form.setAttribute('aria-busy', 'true');
    frame.querySelector('.lp-board').append(form);
    subscription().handler({ type: 'worker_run.changed' });
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads when another controller asks for a reload', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    application
        .getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="board-refresh"]'),
            'board-refresh',
        )
        .reload();
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads after a reconnect, not after the first open', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    subscription().options.onOpen();
    vi.advanceTimersByTime(300);
    expect(frame.reload).not.toHaveBeenCalled();
    subscription().options.onReconnect();
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('stops listening on disconnect', async () => {
    await mount();
    const listening = subscription();
    document.body.replaceChildren();
    await Promise.resolve();
    expect(listening.removed).toBe(true);
});

it('drops a pending reload on disconnect', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    subscription().handler({ type: 'worker_run.changed' });
    document.body.replaceChildren();
    await Promise.resolve();
    vi.advanceTimersByTime(300);
    expect(frame.reload).not.toHaveBeenCalled();
});
