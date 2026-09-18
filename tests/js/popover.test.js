/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import PopoverController from '../../assets/controllers/popover_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(async () => {
    document.body.innerHTML = `<div data-controller="popover">
        <button data-popover-target="trigger" aria-expanded="false">Choose project</button>
        <div data-popover-target="panel" hidden><a href="/projects/second">Second project</a></div>
    </div><button id="outside">Outside</button>`;
    application = Application.start();
    application.register('popover', PopoverController);
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller]'),
        'popover',
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

it('returns focus on Escape without closing a surrounding drawer', () => {
    controller.open();
    const link = controller.panelTarget.querySelector('a');
    link.focus();
    const outerKeydown = vi.fn();
    document.addEventListener('keydown', outerKeydown);
    const event = new KeyboardEvent('keydown', {
        key: 'Escape',
        bubbles: true,
        cancelable: true,
    });
    link.dispatchEvent(event);
    document.removeEventListener('keydown', outerKeydown);
    expect(controller.panelTarget.hidden).toBe(true);
    expect(controller.triggerTarget.getAttribute('aria-expanded')).toBe(
        'false',
    );
    expect(document.activeElement).toBe(controller.triggerTarget);
    expect(event.defaultPrevented).toBe(true);
    expect(outerKeydown).not.toHaveBeenCalled();
});

it('leaves focus on an outside control when a click closes the panel', () => {
    controller.open();
    const outside = document.getElementById('outside');
    outside.focus();
    outside.click();
    expect(controller.panelTarget.hidden).toBe(true);
    expect(document.activeElement).toBe(outside);
});

it('caches a closed panel without moving focus', () => {
    controller.open();
    const link = controller.panelTarget.querySelector('a');
    link.focus();
    document.dispatchEvent(new Event('turbo:before-cache'));
    expect(controller.panelTarget.hidden).toBe(true);
    expect(controller.triggerTarget.getAttribute('aria-expanded')).toBe(
        'false',
    );
    expect(document.activeElement).toBe(link);
});

it('leaves an open nested dialog in control of Escape', () => {
    controller.open();
    controller.panelTarget.insertAdjacentHTML(
        'beforeend',
        '<dialog open><input></dialog>',
    );
    const input = controller.panelTarget.querySelector('input');
    input.focus();
    input.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    );
    expect(controller.panelTarget.hidden).toBe(false);
    expect(document.activeElement).toBe(input);
});
