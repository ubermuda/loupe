/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import InteractiveTooltipController from '../../assets/controllers/interactive_tooltip_controller.js';

let application;

const rectangle = (left, top, width, height) => ({
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
});

beforeEach(async () => {
    vi.useFakeTimers();
    document.body.innerHTML = `
        <span data-controller="interactive-tooltip"
              data-action="pointerenter->interactive-tooltip#open pointerleave->interactive-tooltip#scheduleClose focusin->interactive-tooltip#open focusout->interactive-tooltip#leaveFocus keydown.esc->interactive-tooltip#close">
            <span id="mark" tabindex="0" data-action="pointerdown->interactive-tooltip#pressed"></span>
            <span id="tooltip" data-interactive-tooltip-target="tooltip"><a id="link" href="#">Open card</a></span>
        </span>
        <button id="outside"></button>`;
    document.querySelector('[data-controller]').getBoundingClientRect = () =>
        rectangle(40, 100, 16, 16);
    document.querySelector('#tooltip').getBoundingClientRect = () =>
        rectangle(0, 0, 288, 80);
    application = Application.start();
    application.register('interactive-tooltip', InteractiveTooltipController);
    await vi.advanceTimersByTimeAsync(0);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
});

const anchor = () => document.querySelector('[data-controller]');
const tooltip = () => document.querySelector('#tooltip');
const isOpen = () => tooltip().hasAttribute('data-open');

it('opens under the anchor when the pointer enters it', () => {
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(isOpen()).toBe(true);
    expect(tooltip().style.left).toBe('40px');
    expect(tooltip().style.top).toBe('124px');
});

it('stays open while the pointer crosses from the anchor into the tooltip', async () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    anchor().dispatchEvent(new Event('pointerleave'));
    await vi.advanceTimersByTimeAsync(100);
    anchor().dispatchEvent(new Event('pointerenter'));
    await vi.advanceTimersByTimeAsync(500);

    expect(isOpen()).toBe(true);
});

it('closes a moment after the pointer leaves', async () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    anchor().dispatchEvent(new Event('pointerleave'));
    await vi.advanceTimersByTimeAsync(200);

    expect(isOpen()).toBe(false);
});

it('flips above the anchor when the tooltip does not fit below', () => {
    anchor().getBoundingClientRect = () => rectangle(40, 700, 16, 16);
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(tooltip().style.top).toBe('612px');
});

it('stays inside the right edge of the window', () => {
    anchor().getBoundingClientRect = () => rectangle(900, 100, 16, 16);
    anchor().dispatchEvent(new Event('pointerenter'));

    expect(tooltip().style.left).toBe('728px');
});

it('stays open while the focus moves from the mark into the link', () => {
    const mark = document.querySelector('#mark');
    mark.focus();
    expect(isOpen()).toBe(true);

    anchor().dispatchEvent(
        new FocusEvent('focusout', {
            relatedTarget: document.querySelector('#link'),
        }),
    );
    expect(isOpen()).toBe(true);
});

it('closes when the focus leaves the anchor', () => {
    document.querySelector('#mark').focus();
    anchor().dispatchEvent(
        new FocusEvent('focusout', {
            relatedTarget: document.querySelector('#outside'),
        }),
    );

    expect(isOpen()).toBe(false);
});

it('closes on Escape', () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    anchor().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

    expect(isOpen()).toBe(false);
});

it('closes on a mouse press of the mark, and not on a touch press', () => {
    const mark = document.querySelector('#mark');
    anchor().dispatchEvent(new Event('pointerenter'));

    const touch = new Event('pointerdown', { bubbles: true });
    touch.pointerType = 'touch';
    mark.dispatchEvent(touch);
    expect(isOpen()).toBe(true);

    const mouse = new Event('pointerdown', { bubbles: true });
    mouse.pointerType = 'mouse';
    mark.dispatchEvent(mouse);
    expect(isOpen()).toBe(false);
});

it('closes when a scroll moves the anchor', () => {
    anchor().dispatchEvent(new Event('pointerenter'));
    window.dispatchEvent(new Event('scroll'));

    expect(isOpen()).toBe(false);
});
