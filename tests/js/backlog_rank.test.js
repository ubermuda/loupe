/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BacklogRankController from '../../assets/controllers/backlog_rank_controller.js';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const row = (
    id,
) => `<div id="row-${id}" data-card-id="${id}" data-backlog-rank-target="row">
    <span class="grip"></span>
    <form data-backlog-rank-target="form" hidden>
        <input type="hidden" data-backlog-rank-field="before">
        <input type="hidden" data-backlog-rank-field="after">
    </form>
</div>`;

beforeEach(async () => {
    document.body.innerHTML = `<div id="list" data-controller="backlog-rank">${row('a')}${row('b')}${row('c')}</div>`;
    application = Application.start();
    application.register('backlog-rank', BacklogRankController);
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.getElementById('list'),
        'backlog-rank',
    );
    // Rows 40px tall, stacked in page order.
    ['a', 'b', 'c'].forEach((id) => {
        const element = document.getElementById(`row-${id}`);
        element.getBoundingClientRect = () => {
            const index = [...element.parentElement.children].indexOf(element);

            return { top: index * 40, height: 40, bottom: index * 40 + 40 };
        };
        element.querySelector('form').requestSubmit = vi.fn();
    });
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

const order = () =>
    [...document.getElementById('list').children].map(
        (element) => element.dataset.cardId,
    );
const form = (id) => document.querySelector(`#row-${id} form`);
const field = (id, name) =>
    form(id).querySelector(`[data-backlog-rank-field="${name}"]`).value;

function pointer(y, extra = {}) {
    return {
        pointerId: 1,
        button: 0,
        clientX: 10,
        clientY: y,
        preventDefault() {},
        ...extra,
    };
}

function drag(id, fromY, toY) {
    controller.press(
        pointer(fromY, {
            currentTarget: document.querySelector(`#row-${id} .grip`),
        }),
    );
    controller.move(pointer(toY));
    controller.release(pointer(toY));
}

it('submits the visible row below the drop', () => {
    drag('c', 90, 10);

    expect(order()).toEqual(['c', 'a', 'b']);
    expect(form('c').requestSubmit).toHaveBeenCalledOnce();
    expect(field('c', 'before')).toBe('a');
    expect(field('c', 'after')).toBe('');
    expect(document.getElementById('row-c').getAttribute('aria-busy')).toBe(
        'true',
    );
});

it('submits the visible row above when the drop lands last', () => {
    drag('a', 10, 200);

    expect(order()).toEqual(['b', 'c', 'a']);
    expect(field('a', 'before')).toBe('');
    expect(field('a', 'after')).toBe('c');
});

it('does not submit a press that never passes the threshold', () => {
    drag('a', 10, 12);

    expect(order()).toEqual(['a', 'b', 'c']);
    expect(form('a').requestSubmit).not.toHaveBeenCalled();
});

it('puts the row back when the rank is refused, and takes a new drag after it', () => {
    drag('c', 90, 10);
    form('c').dispatchEvent(
        new CustomEvent('turbo:submit-end', { detail: { success: false } }),
    );

    expect(order()).toEqual(['a', 'b', 'c']);
    expect(document.getElementById('row-c').hasAttribute('aria-busy')).toBe(
        false,
    );

    drag('b', 50, 200);
    expect(order()).toEqual(['a', 'c', 'b']);
});

it('lets the stream of a refused rank render its reason, and keeps an error page out', () => {
    drag('c', 90, 10);
    const answer = (contentType) => {
        const event = new CustomEvent('turbo:before-fetch-response', {
            cancelable: true,
            detail: { fetchResponse: { succeeded: false, contentType } },
        });
        form('c').dispatchEvent(event);

        return event.defaultPrevented;
    };

    expect(answer('text/vnd.turbo-stream.html; charset=UTF-8')).toBe(false);
    expect(answer('text/html; charset=UTF-8')).toBe(true);
});

it('refuses a second drag while a rank is in flight', () => {
    drag('c', 90, 10);
    drag('b', 50, 200);

    expect(order()).toEqual(['c', 'a', 'b']);
    expect(form('b').requestSubmit).not.toHaveBeenCalled();
});

it('commits a drop released away from the grip, after the row moved', () => {
    controller.press(
        pointer(90, { currentTarget: document.querySelector('#row-c .grip') }),
    );
    const at = (type, y) =>
        window.dispatchEvent(
            Object.assign(new Event(type), {
                pointerId: 1,
                clientX: 10,
                clientY: y,
            }),
        );
    at('pointermove', 10);
    at('pointerup', 10);

    expect(order()).toEqual(['c', 'a', 'b']);
    expect(form('c').requestSubmit).toHaveBeenCalledOnce();
});

it('puts the row back and submits nothing on a pointer cancel', () => {
    controller.press(
        pointer(90, { currentTarget: document.querySelector('#row-c .grip') }),
    );
    controller.move(pointer(10));
    controller.cancel(pointer(10));

    expect(order()).toEqual(['a', 'b', 'c']);
    expect(form('c').requestSubmit).not.toHaveBeenCalled();
});
