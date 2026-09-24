/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardDragController from '../../assets/controllers/board_drag_controller.js';

let application;

beforeEach(() => {
    application = Application.start();
    application.register('board-drag', BoardDragController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

/** Every group covers the same box, so only the lane rule can tell them apart. */
async function mount(groups) {
    document.body.innerHTML = `<div data-controller="board-drag">${groups}</div>`;
    for (const group of document.querySelectorAll(
        '[data-board-drag-target="group"]',
    )) {
        group.getBoundingClientRect = () => ({
            left: 0,
            right: 100,
            top: 0,
            bottom: 100,
        });
    }
    await new Promise((resolve) => setTimeout(resolve, 0));

    return application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="board-drag"]'),
        'board-drag',
    );
}

it('offers only the cells of the lane the drag started in', async () => {
    const controller = await mount(`
        <div id="other-lane" data-board-drag-target="group" data-lane="epic-b" data-column="c1"></div>
        <div id="own-lane" data-board-drag-target="group" data-lane="epic-a" data-column="c1"></div>`);
    controller.originGroup = document.getElementById('own-lane');

    expect(controller.groupUnder(50, 50)).toBe(
        document.getElementById('own-lane'),
    );

    controller.originGroup = document.getElementById('other-lane');
    expect(controller.groupUnder(50, 50)).toBe(
        document.getElementById('other-lane'),
    );
});

it('offers every group of a board with no lanes', async () => {
    const controller = await mount(`
        <div id="first" data-board-drag-target="group" data-column="c1"></div>
        <div id="second" data-board-drag-target="group" data-column="c2"></div>`);
    controller.originGroup = document.getElementById('second');

    expect(controller.groupUnder(50, 50)).toBe(
        document.getElementById('first'),
    );
});
