/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import FrameRefreshController from '../../assets/controllers/frame_refresh_controller.js';

let application;

beforeEach(() => {
    window.history.replaceState({}, '', '/projects/1/site-review');
    application = Application.start();
    application.register('frame-refresh', FrameRefreshController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

async function mount(src) {
    document.body.innerHTML = `<div data-controller="frame-refresh">
        <turbo-frame id="page" data-frame-refresh-target="frame"${src ? ` src="${src}"` : ''}></turbo-frame>
    </div>`;
    const frame = document.querySelector('turbo-frame');
    frame.reload = vi.fn();
    await new Promise((resolve) => setTimeout(resolve, 0));
    return [
        application.getControllerForElementAndIdentifier(
            document.querySelector('[data-controller="frame-refresh"]'),
            'frame-refresh',
        ),
        frame,
    ];
}

it('gives a frame with no src the current page, which loads it', async () => {
    const [controller, frame] = await mount();
    controller.reload();
    expect(frame.getAttribute('src')).toBe(window.location.href);
    expect(frame.reload).not.toHaveBeenCalled();
});

it('reloads a frame that already has a src', async () => {
    const [controller, frame] = await mount('/projects/1/site-review');
    controller.reload();
    expect(frame.reload).toHaveBeenCalledOnce();
});
