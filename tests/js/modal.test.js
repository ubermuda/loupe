/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ModalController from '../../assets/controllers/modal_controller.js';

let application;
let controller;
let dialog;
let completions;

beforeEach(async () => {
    completions = [];
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    document.body.innerHTML =
        '<div data-controller="modal"><dialog data-modal-target="dialog"><textarea>Keep my draft.</textarea></dialog></div>';
    dialog = document.querySelector('dialog');
    dialog.showModal = vi.fn(() => {
        dialog.open = true;
    });
    dialog.close = vi.fn(() => {
        dialog.open = false;
    });
    dialog.getAnimations = () => [];
    dialog.animate = vi.fn(() => ({
        finished: new Promise((resolve) => completions.push(resolve)),
        cancel: vi.fn(),
    }));
    application = Application.start();
    application.register('modal', ModalController);
    await new Promise((resolve) => setTimeout(resolve, 0));
    controller = application.getControllerForElementAndIdentifier(
        document.body.firstElementChild,
        'modal',
    );
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.unstubAllGlobals();
});

async function finish(index) {
    completions[index]();
    await new Promise((resolve) => setTimeout(resolve, 0));
}

it('does not let an earlier close dismiss a reopened dialog', async () => {
    controller.open();
    controller.close();
    controller.open();
    await finish(1);
    expect(dialog.open).toBe(true);
    expect(dialog.close).not.toHaveBeenCalled();
    expect(dialog.querySelector('textarea').value).toBe('Keep my draft.');
});

it('closes once when dismissal repeats during the animation', async () => {
    controller.open();
    controller.close();
    controller.close();
    await finish(1);
    expect(dialog.open).toBe(true);
    expect(dialog.classList.contains('is-closing')).toBe(true);
    await finish(2);
    expect(dialog.close).toHaveBeenCalledOnce();
});

it('ignores a pending close after the controller disconnects', async () => {
    controller.open();
    controller.close();
    controller.disconnect();
    await finish(1);
    expect(dialog.close).not.toHaveBeenCalled();
});

it('does not animate a dialog that is already closed', () => {
    controller.close();
    expect(dialog.animate).not.toHaveBeenCalled();
});

it('slides an opted-in drawer horizontally', () => {
    controller.drawerValue = true;
    controller.open();
    expect(dialog.animate.mock.calls[0][0]).toEqual([
        { transform: 'translateX(100%)' },
        { transform: 'translateX(0)' },
    ]);
    controller.close();
    expect(dialog.animate.mock.calls[1][0]).toEqual([
        { transform: 'translateX(0)' },
        { transform: 'translateX(100%)' },
    ]);
});

it('times a drawer slide at 200ms with the ease curve both ways', () => {
    controller.drawerValue = true;
    controller.open();
    controller.close();
    expect(dialog.animate).toHaveBeenCalledTimes(2);
    for (const call of dialog.animate.mock.calls) {
        expect(call[1]).toMatchObject({ duration: 200, easing: 'ease' });
    }
});

it('returns focus to the trigger once the close animation ends', async () => {
    const trigger = document.createElement('button');
    document.body.append(trigger);
    trigger.focus();
    controller.open();
    dialog.querySelector('textarea').focus();
    controller.close();
    await finish(1);
    expect(document.activeElement).toBe(trigger);
});

it('suppresses drawer motion when reduced motion is requested', () => {
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    controller.drawerValue = true;
    controller.open();
    controller.close();
    expect(dialog.animate).not.toHaveBeenCalled();
});
