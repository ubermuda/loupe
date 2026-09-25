/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BoardRefreshController from '../../assets/controllers/board_refresh_controller.js';
import { reset } from '../../assets/lib/live.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
    reset();
    application = Application.start();
    application.register('board-refresh', BoardRefreshController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

async function mount(attributes = '') {
    document.body.innerHTML = `<div data-controller="board-refresh" data-board-refresh-board-value="/projects/1/board">
        <turbo-frame id="board-frame" refresh="morph" data-board-refresh-target="frame"${attributes}></turbo-frame>
    </div>`;
    const frame = document.querySelector('turbo-frame');
    const calls = [];
    const setAttribute = frame.setAttribute.bind(frame);
    const removeAttribute = frame.removeAttribute.bind(frame);
    frame.setAttribute = (name, value) => {
        calls.push(`set ${name}`);
        setAttribute(name, value);
    };
    frame.removeAttribute = (name) => {
        calls.push(`remove ${name}`);
        removeAttribute(name);
    };
    frame.reload = vi.fn();
    await settle();

    return [
        application.getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="board-refresh"]'),
            'board-refresh',
        ),
        frame,
        calls,
    ];
}

it('gives the frame its src while disabled, so Turbo loads nothing until a reload', async () => {
    const [controller, frame, calls] = await mount();
    expect(calls).toEqual([
        'set disabled',
        'set src',
        'set complete',
        'remove disabled',
    ]);
    expect(frame.getAttribute('src')).toBe('/projects/1/board');
    expect(frame.hasAttribute('complete')).toBe(true);
    expect(frame.hasAttribute('disabled')).toBe(false);

    controller.reload();
    expect(frame.reload).toHaveBeenCalledOnce();
});

it('leaves a frame that is already complete alone', async () => {
    const [, , calls] = await mount(' src="/projects/1/board" complete');
    expect(calls).toEqual([]);
});
