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

function requestReload() {
    application
        .getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="board-refresh"]'),
            'board-refresh',
        )
        .reload();
}

it('subscribes to no live change, so a column change does not reload the frame', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    expect(live.subscriptions).toEqual([]);
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
});

it('leaves the connected marker to the board live controller', async () => {
    await mount();
    expect(
        document
            .querySelector('[data-controller="board-refresh"]')
            .hasAttribute('data-board-refresh-connected'),
    ).toBe(false);
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

it('gives a frame with no src the board url on a reload', async () => {
    const frame = await mount();
    requestReload();
    vi.advanceTimersByTime(300);
    expect(frame.getAttribute('src')).toBe('/projects/1/board');
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads when another controller asks for a reload', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    requestReload();
    vi.advanceTimersByTime(299);
    expect(frame.reload).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('coalesces a burst of reload requests into one reload', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    requestReload();
    vi.advanceTimersByTime(200);
    requestReload();
    vi.advanceTimersByTime(200);
    requestReload();
    vi.advanceTimersByTime(299);
    expect(frame.reload).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('reloads a steady stream of requests at least once per max wait', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const reloadTimes = [];
    frame.reload.mockImplementation(() => reloadTimes.push(Date.now()));
    const start = Date.now();
    for (let elapsed = 0; elapsed < 5000; elapsed += 100) {
        requestReload();
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
    requestReload();
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
    requestReload();
    vi.advanceTimersByTime(300);
    board.classList.remove('lp-board--dragging');
    card.setAttribute('aria-busy', 'true');
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    card.removeAttribute('aria-busy');
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('defers a reload while a dialog is open, and reloads once it closes', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const dialog = document.createElement('dialog');
    dialog.setAttribute('open', '');
    frame.querySelector('.lp-board').append(dialog);
    requestReload();
    vi.advanceTimersByTime(3000);
    expect(frame.reload).not.toHaveBeenCalled();
    dialog.removeAttribute('open');
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('ignores a closed dialog', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    frame.querySelector('.lp-board').append(document.createElement('dialog'));
    requestReload();
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('ignores a busy form that is not a card move', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    const form = document.createElement('form');
    form.setAttribute('aria-busy', 'true');
    frame.querySelector('.lp-board').append(form);
    requestReload();
    vi.advanceTimersByTime(300);
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('drops a pending reload on disconnect', async () => {
    const frame = await mount({ src: '/projects/1/board' });
    requestReload();
    document.body.replaceChildren();
    await Promise.resolve();
    vi.advanceTimersByTime(300);
    expect(frame.reload).not.toHaveBeenCalled();
});
