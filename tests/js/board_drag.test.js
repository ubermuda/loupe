/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BoardDragController from '../../assets/controllers/board_drag_controller.js';

const STREAM = 'text/vnd.turbo-stream.html; charset=UTF-8';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function card(id) {
    return `<article id="board-card-${id}" data-board-drag-target="card">
        <form hidden data-board-drag-target="moveForm">
            <select name="move_${id}[column]"><option value="backlog">Backlog</option><option value="next">Next</option></select>
            <input name="move_${id}[position]">
        </form>
    </article>`;
}

beforeEach(async () => {
    document.body.innerHTML = `<div id="board" data-controller="board-drag">
        <p data-board-drag-target="message" data-message="The move failed."></p>
        <div id="board-group-backlog" data-board-drag-target="group" data-column="backlog" data-rankable="1">${card('a')}${card('b')}</div>
        <div id="board-group-next" data-board-drag-target="group" data-column="next" data-rankable="1"></div>
    </div>`;
    application = Application.start();
    application.register('board-drag', BoardDragController);
    await settle();
    controller = application.getControllerForElementAndIdentifier(
        document.getElementById('board'),
        'board-drag',
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

/** Moves card a into Next as a drop would, and returns its form. */
function drop() {
    const moved = document.getElementById('board-card-a');
    const next = document.getElementById('board-group-next');
    const origin = {
        group: document.getElementById('board-group-backlog'),
        before: document.getElementById('board-card-b'),
    };
    const form = moved.querySelector('form');
    form.requestSubmit = vi.fn();
    next.append(moved);
    controller.submitMove(moved, next, 0, origin);

    return form;
}

function respond(form, fetchResponse) {
    const event = new CustomEvent('turbo:before-fetch-response', {
        bubbles: true,
        cancelable: true,
        detail: { fetchResponse },
    });
    form.dispatchEvent(event);

    return event.defaultPrevented;
}

function finish(form, success) {
    form.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            bubbles: true,
            detail: { success },
        }),
    );
}

function titles(column) {
    return Array.from(
        document.querySelectorAll(`#board-group-${column} article`),
    ).map((element) => element.id.replace('board-card-', ''));
}

it('keeps a refused answer from rendering, and puts the card back', () => {
    const form = drop();

    expect(
        respond(form, {
            succeeded: false,
            contentType: 'text/html; charset=UTF-8',
        }),
    ).toBe(true);
    finish(form, false);

    expect(titles('backlog')).toEqual(['a', 'b']);
    expect(titles('next')).toEqual([]);
    expect(
        document.querySelector('[data-board-drag-target="message"]')
            .textContent,
    ).toBe('The move failed.');
});

it('cancels the default handling of a refused stream too', () => {
    const form = drop();

    expect(respond(form, { succeeded: false, contentType: STREAM })).toBe(true);
});

it('keeps a success that is not a stream from rendering, and puts the card back', () => {
    const form = drop();

    expect(
        respond(form, {
            succeeded: true,
            contentType: 'text/html; charset=UTF-8',
        }),
    ).toBe(true);
    // Turbo reports a prevented answer with the status alone, so a 200 says success.
    finish(form, true);

    expect(titles('backlog')).toEqual(['a', 'b']);
});

it('lets a successful stream render, and refuses a new drag until the card is placed', () => {
    const form = drop();
    const moved = document.getElementById('board-card-a');

    expect(respond(form, { succeeded: true, contentType: STREAM })).toBe(false);
    finish(form, true);

    expect(titles('next')).toEqual(['a']);
    expect(controller.pendingForm).not.toBeNull();
    expect(moved.getAttribute('aria-busy')).toBe('true');

    document.dispatchEvent(
        new CustomEvent('board:placed', { detail: { cardId: 'other' } }),
    );
    expect(controller.pendingForm).not.toBeNull();

    moved.dispatchEvent(
        new CustomEvent('board:placed', {
            bubbles: true,
            detail: { cardId: 'a' },
        }),
    );
    expect(controller.pendingForm).toBeNull();
    expect(moved.hasAttribute('aria-busy')).toBe(false);
});

it('releases the drag when the page cannot place the card', () => {
    const form = drop();

    respond(form, { succeeded: true, contentType: STREAM });
    finish(form, true);
    document.dispatchEvent(
        new CustomEvent('board:place-missed', { detail: { cardId: 'a' } }),
    );

    expect(controller.pendingForm).toBeNull();
});
