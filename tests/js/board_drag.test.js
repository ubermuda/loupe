/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BoardDragController from '../../assets/controllers/board_drag_controller.js';
import { on, reset as resetLive } from '../../assets/lib/live.js';

const STREAM = 'text/vnd.turbo-stream.html; charset=UTF-8';

let application;
let controller;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const PROTOTYPE = '00000000-0000-4000-8000-000000000000';

function card(id, prefix = 'board-card-') {
    return `<article id="${prefix}${id}" data-card-id="${id}" data-board-drag-target="card"></article>`;
}

const MOVE_FORM = `<form hidden data-board-drag-target="moveForm" action="/cards/${PROTOTYPE}/move" data-move-card-id="${PROTOTYPE}">
    <select id="move_card_${PROTOTYPE}_column" name="move_card_${PROTOTYPE}[column]"><option value="backlog">Backlog</option><option value="next">Next</option><option value="waiting">Waiting</option></select>
    <input id="move_card_${PROTOTYPE}_position" name="move_card_${PROTOTYPE}[position]">
    <input type="checkbox" value="1" id="move_card_${PROTOTYPE}_unmanage" name="move_card_${PROTOTYPE}[unmanage]">
</form>`;

const moveForm = () =>
    document.querySelector('form[data-board-drag-target="moveForm"]');

beforeEach(async () => {
    document.body.innerHTML = `<div id="board" data-controller="board-drag">
        <p data-board-drag-target="message" data-message="The move failed."></p>
        ${MOVE_FORM}
        <a id="bucket" data-board-drag-target="group" data-board-bucket data-column="waiting" data-rankable="0">Waiting</a>
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
    resetLive();
});

/** Moves card a into Next as a drop would, and returns its form. */
function drop() {
    const moved = document.getElementById('board-card-a');
    const next = document.getElementById('board-group-next');
    const origin = {
        group: document.getElementById('board-group-backlog'),
        before: document.getElementById('board-card-b'),
    };
    const form = moveForm();
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
            detail: { success, formSubmission: { formElement: form } },
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

it('adds the option of a column that appeared after the page loaded, and submits it', () => {
    const board = document.getElementById('board');
    board.insertAdjacentHTML(
        'beforeend',
        `<section class="lp-board__column" aria-label="Review">
            <div id="board-group-review" data-board-drag-target="group" data-column="review" data-rankable="1"></div>
        </section>`,
    );
    const moved = document.getElementById('board-card-a');
    const review = document.getElementById('board-group-review');
    const form = moveForm();
    form.requestSubmit = vi.fn();
    review.append(moved);
    controller.submitMove(moved, review, 0, {
        group: document.getElementById('board-group-backlog'),
        before: document.getElementById('board-card-b'),
    });

    const select = form.querySelector('select');
    expect(select.value).toBe('review');
    expect(select.selectedOptions[0].textContent).toBe('Review');
    expect(select.querySelectorAll('option[value="review"]')).toHaveLength(1);
    expect(form.requestSubmit).toHaveBeenCalledOnce();
});

it('adds no option for a column the select already has', () => {
    const form = drop();

    expect(form.querySelectorAll('option')).toHaveLength(3);
    expect(form.querySelector('select').value).toBe('next');
});

it('names the one move form after each card it submits', () => {
    const form = drop();
    const fields = () =>
        [...form.elements].map((field) => [field.name, field.id]);

    expect(form.getAttribute('action')).toBe('/cards/a/move');
    expect(fields()).toEqual([
        ['move_card_a[column]', 'move_card_a_column'],
        ['move_card_a[position]', 'move_card_a_position'],
        ['move_card_a[unmanage]', 'move_card_a_unmanage'],
    ]);

    finish(form, false);
    const second = document.getElementById('board-card-b');
    const next = document.getElementById('board-group-next');
    next.append(second);
    controller.submitMove(second, next, 0, {
        group: document.getElementById('board-group-backlog'),
        before: null,
    });

    expect(form.getAttribute('action')).toBe('/cards/b/move');
    expect(fields()).toEqual([
        ['move_card_b[column]', 'move_card_b_column'],
        ['move_card_b[position]', 'move_card_b_position'],
        ['move_card_b[unmanage]', 'move_card_b_unmanage'],
    ]);
    expect(form.requestSubmit).toHaveBeenCalledTimes(2);
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

describe('the lane heads a drop changes', () => {
    /** The epics this page asks to redraw, in the order it asks. */
    function watchLanes() {
        const epics = [];
        on('board.card_changed', (change) => epics.push(change.cardId));

        return epics;
    }

    it('asks the lane the card left and the lane it joined to redraw', () => {
        document.getElementById('board-group-backlog').dataset.lane = 'epic-a';
        document.getElementById('board-group-next').dataset.lane = 'epic-b';
        const epics = watchLanes();

        const form = drop();
        respond(form, { succeeded: true, contentType: STREAM });
        finish(form, true);

        expect(epics).toEqual(['epic-b', 'epic-a']);
    });

    it('asks nothing of "Other cards" or of a board with no lanes', () => {
        document.getElementById('board-group-backlog').dataset.lane = 'other';
        const epics = watchLanes();

        const form = drop();
        respond(form, { succeeded: true, contentType: STREAM });
        finish(form, true);

        expect(epics).toEqual([]);
    });

    it('asks nothing when the move is refused', () => {
        document.getElementById('board-group-next').dataset.lane = 'epic-b';
        const epics = watchLanes();

        const form = drop();
        respond(form, { succeeded: false, contentType: 'text/html' });
        finish(form, false);

        expect(epics).toEqual([]);
    });
});

describe('a drop on the bucket', () => {
    const rectangle = (left, top, right, bottom) => () => ({
        left,
        top,
        right,
        bottom,
        width: right - left,
        height: bottom - top,
    });

    /** Presses card a, then moves the pointer to (x, y). */
    function dragTo(x, y) {
        document.getElementById('bucket').getBoundingClientRect = rectangle(
            0,
            0,
            100,
            40,
        );
        document.getElementById('board-group-backlog').getBoundingClientRect =
            rectangle(200, 60, 300, 400);
        document.getElementById('board-group-next').getBoundingClientRect =
            rectangle(400, 60, 500, 400);
        const moved = document.getElementById('board-card-a');
        controller.press({
            pointerId: 1,
            pointerType: 'mouse',
            button: 0,
            target: moved,
            clientX: 250,
            clientY: 100,
        });
        controller.pointerMove({
            pointerId: 1,
            clientX: x,
            clientY: y,
            preventDefault: () => {},
        });

        return moved;
    }

    it('marks the bucket and keeps the drop marker out of it', () => {
        dragTo(50, 20);

        const bucket = document.getElementById('bucket');
        expect(bucket.classList.contains('lp-board-backlog--over')).toBe(true);
        expect(bucket.querySelector('.lp-board__placeholder')).toBeNull();

        controller.pointerMove({
            pointerId: 1,
            clientX: 450,
            clientY: 100,
            preventDefault: () => {},
        });
        expect(bucket.classList.contains('lp-board-backlog--over')).toBe(false);
    });

    it('takes no drop just below its edge, where a column still does', () => {
        dragTo(50, 20);
        document.getElementById('board-group-backlog').getBoundingClientRect =
            rectangle(0, 60, 100, 400);

        expect(controller.groupUnder(50, 40)?.id).toBe('bucket');
        expect(controller.groupUnder(50, 50)?.id).toBe('board-group-backlog');
    });

    it('sends the card to the end of the column it names, and hides it until the answer', () => {
        const moved = dragTo(50, 20);
        const form = moveForm();
        form.requestSubmit = vi.fn();

        controller.pointerUp({
            pointerId: 1,
            type: 'pointerup',
            clientX: 50,
            clientY: 20,
            target: document.getElementById('bucket'),
        });

        expect(form.requestSubmit).toHaveBeenCalledOnce();
        expect(form.querySelector('select').value).toBe('waiting');
        expect(form.querySelector('input[name$="[position]"]').value).toBe('');
        expect(moved.classList.contains('lp-board-card--sent')).toBe(true);
        expect(titles('backlog')).toEqual(['a', 'b']);
        expect(
            document.getElementById('bucket').querySelector('article'),
        ).toBeNull();
        expect(
            document
                .getElementById('bucket')
                .classList.contains('lp-board-backlog--over'),
        ).toBe(false);

        respond(form, {
            succeeded: false,
            contentType: 'text/html; charset=UTF-8',
        });
        finish(form, false);

        expect(moved.classList.contains('lp-board-card--sent')).toBe(false);
        expect(titles('backlog')).toEqual(['a', 'b']);
    });
});

describe('a card of an Up next deck', () => {
    beforeEach(async () => {
        document
            .getElementById('board')
            .insertAdjacentHTML(
                'beforeend',
                `<div id="deck" data-board-drag-target="group" data-board-bucket data-column="waiting" data-lane="epic" data-rankable="0">${card('c', 'board-deck-card-')}</div>`,
            );
        await settle();
        document.getElementById('deck').getBoundingClientRect = () => ({
            left: 600,
            top: 0,
            right: 800,
            bottom: 80,
        });
    });

    function dragDeckCard(x, y) {
        const moved = document.getElementById('board-deck-card-c');
        moveForm().requestSubmit = vi.fn();
        controller.press({
            pointerId: 1,
            pointerType: 'mouse',
            button: 0,
            target: moved,
            clientX: 700,
            clientY: 40,
        });
        controller.pointerMove({
            pointerId: 1,
            clientX: x,
            clientY: y,
            preventDefault: () => {},
        });
        controller.pointerUp({
            pointerId: 1,
            type: 'pointerup',
            clientX: x,
            clientY: y,
            target: moved,
        });

        return moved;
    }

    it('moves nothing when it is dropped back on its own deck', () => {
        const moved = dragDeckCard(650, 40);

        expect(moveForm().requestSubmit).not.toHaveBeenCalled();
        expect(moved.classList.contains('lp-board-card--sent')).toBe(false);
        expect(document.getElementById('deck').contains(moved)).toBe(true);
    });

    it('moves nothing when it is dropped on the button of the Backlog it already waits in', () => {
        document.getElementById('bucket').getBoundingClientRect = () => ({
            left: 0,
            top: 0,
            right: 100,
            bottom: 40,
        });
        const moved = dragDeckCard(50, 20);

        expect(moveForm().requestSubmit).not.toHaveBeenCalled();
        expect(moved.classList.contains('lp-board-card--sent')).toBe(false);
        expect(document.getElementById('deck').contains(moved)).toBe(true);
    });

    it('takes the marker out of a column when the pointer comes back over the fanned deck', () => {
        const deck = document.getElementById('deck');
        deck.classList.add('lp-deck');
        deck.insertAdjacentHTML(
            'beforeend',
            '<article class="lp-deck__card" data-deck-index="1"></article>',
        );
        deck.lastElementChild.getBoundingClientRect = () => ({
            left: 300,
            top: 0,
            right: 500,
            bottom: 80,
            width: 200,
        });
        document.getElementById('board-group-next').getBoundingClientRect =
            () => ({ left: 400, top: 100, right: 500, bottom: 400 });
        const moved = document.getElementById('board-deck-card-c');
        controller.press({
            pointerId: 1,
            pointerType: 'mouse',
            button: 0,
            target: moved,
            clientX: 700,
            clientY: 40,
        });
        const move = (clientX, clientY) =>
            controller.pointerMove({
                pointerId: 1,
                clientX,
                clientY,
                preventDefault: () => {},
            });
        const marker = () =>
            document.querySelector('#board-group-next .lp-board__placeholder');

        move(450, 200);
        expect(marker()).not.toBeNull();

        move(350, 40);
        expect(controller.groupUnder(350, 40)).toBe(deck);
        expect(marker()).toBeNull();
        expect(deck.classList.contains('lp-board-backlog--over')).toBe(true);
    });

    it('takes the marker out of a column when the pointer leaves every group', () => {
        document.getElementById('board-group-next').getBoundingClientRect =
            () => ({ left: 400, top: 100, right: 500, bottom: 400 });
        const moved = document.getElementById('board-deck-card-c');
        controller.press({
            pointerId: 1,
            pointerType: 'mouse',
            button: 0,
            target: moved,
            clientX: 700,
            clientY: 40,
        });
        const move = (clientX, clientY) =>
            controller.pointerMove({
                pointerId: 1,
                clientX,
                clientY,
                preventDefault: () => {},
            });

        move(450, 200);
        move(1200, 900);

        expect(document.querySelector('.lp-board__placeholder')).toBeNull();
    });

    it('waits for the placement of the card it names, whatever its element id', () => {
        document.getElementById('board-group-next').getBoundingClientRect =
            () => ({ left: 400, top: 60, right: 500, bottom: 400 });
        const moved = dragDeckCard(450, 100);
        const form = moveForm();

        expect(form.requestSubmit).toHaveBeenCalledOnce();
        expect(form.querySelector('select').value).toBe('next');
        respond(form, { succeeded: true, contentType: STREAM });
        finish(form, true);
        expect(controller.pendingForm).not.toBeNull();

        document.dispatchEvent(
            new CustomEvent('board:placed', { detail: { cardId: 'c' } }),
        );
        expect(controller.pendingForm).toBeNull();
    });

    it('marks the slot the card left in its fan with its ghost', () => {
        const moved = document.getElementById('board-deck-card-c');
        moved.dataset.deckIndex = '2';
        controller.press({
            pointerId: 1,
            pointerType: 'mouse',
            button: 0,
            target: moved,
            clientX: 700,
            clientY: 40,
        });
        controller.pointerMove({
            pointerId: 1,
            clientX: 650,
            clientY: 40,
            preventDefault: () => {},
        });

        expect(controller.ghost.style.getPropertyValue('--deck-order')).toBe(
            '2',
        );
        controller.abandon();
    });

    it('lands a card dropped back on its deck from where it was let go, not from the pile', () => {
        const deck = document.getElementById('deck');
        deck.classList.add('lp-deck');
        const moved = document.getElementById('board-deck-card-c');
        moved.getBoundingClientRect = () =>
            moved.classList.contains('lp-board-card--dragging')
                ? { left: 500, top: 30, width: 200, height: 70 }
                : { left: 600, top: 5, width: 200, height: 70 };
        moved.animate = vi.fn();

        dragDeckCard(650, 40);

        expect(moved.animate).toHaveBeenCalledTimes(1);
        const [[from, to]] = moved.animate.mock.calls[0];
        expect(from.translate).toBe('calc(0px + -100px) calc(0px + 25px)');
        expect(to.translate).toBe('0px 0px');
        expect(moved.style.transition).toBe('');
    });

    it('takes no drop on the deck of a collapsed lane that a search reveals', () => {
        const deck = document.getElementById('deck');
        deck.classList.add('lp-deck');
        const lane = document.createElement('section');
        lane.className = 'lp-board-lane--collapsed lp-board-lane--revealed';
        deck.before(lane);
        lane.append(deck);

        expect(controller.groupUnder(700, 40)).toBe(null);

        lane.classList.remove('lp-board-lane--collapsed');
        expect(controller.groupUnder(700, 40)).toBe(deck);
    });

    it('stops the landing when the card is grabbed again before it lands', () => {
        document.getElementById('deck').classList.add('lp-deck');
        const moved = document.getElementById('board-deck-card-c');
        const landing = { cancel: vi.fn() };
        moved.animate = vi.fn(() => landing);

        dragDeckCard(650, 40);
        dragDeckCard(650, 40);

        expect(landing.cancel).toHaveBeenCalledTimes(1);
    });

    it('leaves a card that moves out of its deck to the move', () => {
        const moved = document.getElementById('board-deck-card-c');
        moved.animate = vi.fn();
        document.getElementById('board-group-next').getBoundingClientRect =
            () => ({ left: 200, top: 0, right: 400, bottom: 400 });

        dragDeckCard(300, 100);

        expect(moved.animate).not.toHaveBeenCalled();
    });

    it('holds the fan open after a drop on the deck until the pointer leaves it', () => {
        const deck = document.getElementById('deck');
        deck.classList.add('lp-deck');
        const pointerAt = (clientX, clientY) =>
            window.dispatchEvent(
                Object.assign(new Event('pointermove'), { clientX, clientY }),
            );

        dragDeckCard(650, 40);
        expect(deck.classList.contains('lp-deck--open')).toBe(true);

        deck.classList.remove('lp-deck--open');
        deck.dispatchEvent(
            new CustomEvent('turbo:morph-element', { bubbles: true }),
        );
        expect(deck.classList.contains('lp-deck--open')).toBe(true);

        pointerAt(700, 60);
        expect(deck.classList.contains('lp-deck--open')).toBe(true);

        pointerAt(700, 200);
        expect(deck.classList.contains('lp-deck--open')).toBe(false);
    });
});

describe('a drop refused because the card is managed', () => {
    const OFFER = 'This card is managed. Make it unmanaged and move it?';
    const REFUSAL = 'This card is managed. Make it unmanaged to move it there.';
    const headers = {
        'X-Card-Managed-Offer': OFFER,
        'X-Card-Move-Refusal': REFUSAL,
    };
    const managed = {
        succeeded: false,
        contentType: 'text/html; charset=UTF-8',
        header: (name) => headers[name] ?? null,
    };

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('asks the question, and sends the same move again with unmanage on yes', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const form = drop();
        const unmanage = form.querySelector('input[name$="[unmanage]"]');

        expect(respond(form, managed)).toBe(true);
        finish(form, false);
        expect(confirm).not.toHaveBeenCalled();
        expect(titles('next')).toEqual(['a']);
        await settle();

        expect(confirm).toHaveBeenCalledWith(OFFER);
        expect(form.requestSubmit).toHaveBeenCalledTimes(2);
        expect(unmanage.checked).toBe(true);
        expect(titles('next')).toEqual(['a']);
        expect(controller.pendingForm).toBe(form);

        expect(respond(form, { succeeded: true, contentType: STREAM })).toBe(
            false,
        );
        finish(form, true);
        expect(titles('next')).toEqual(['a']);
    });

    it('puts the card back on no', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const form = drop();

        respond(form, managed);
        finish(form, false);
        await settle();

        expect(form.requestSubmit).toHaveBeenCalledOnce();
        expect(titles('backlog')).toEqual(['a', 'b']);
        expect(controller.pendingForm).toBeNull();
        expect(
            document.querySelector('[data-board-drag-target="message"]')
                .textContent,
        ).toBe(REFUSAL);
    });

    it('asks nothing when the refusal carries no question', async () => {
        const confirm = vi.spyOn(window, 'confirm');
        const form = drop();

        respond(form, { ...managed, header: () => null });
        finish(form, false);
        await settle();

        expect(confirm).not.toHaveBeenCalled();
        expect(titles('backlog')).toEqual(['a', 'b']);
    });

    it('asks once, and puts the card back when the second answer is refused too', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const form = drop();

        respond(form, managed);
        finish(form, false);
        await settle();
        respond(form, managed);
        finish(form, false);
        await settle();

        expect(confirm).toHaveBeenCalledOnce();
        expect(titles('backlog')).toEqual(['a', 'b']);
    });

    it('clears unmanage on the next drop', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const form = drop();
        respond(form, managed);
        finish(form, false);
        await settle();
        respond(form, managed);
        finish(form, false);
        await settle();

        drop();

        expect(form.querySelector('input[name$="[unmanage]"]').checked).toBe(
            false,
        );
    });
});
