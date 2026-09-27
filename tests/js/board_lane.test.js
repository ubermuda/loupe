/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardLaneController from '../../assets/controllers/board_lane_controller.js';

const KEY = 'loupe.board.collapsed-lanes.project-1';

let application;

beforeEach(() => {
    window.localStorage.clear();
    application = Application.start();
    application.register('board-lane', BoardLaneController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    window.localStorage.clear();
});

function laneMarkup(epic, project = 'project-1') {
    return `<section data-controller="board-lane"
                     data-board-lane-project-value="${project}"
                     data-board-lane-epic-value="${epic}"
                     data-lane="${epic}">
        <button type="button" aria-expanded="true"
                data-board-lane-target="toggle"
                data-action="board-lane#toggle">Toggle</button>
        <div class="cells"></div>
    </section>`;
}

async function mount(...epics) {
    document.body.innerHTML = epics.map((epic) => laneMarkup(epic)).join('');
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function lane(epic) {
    return document.querySelector(`[data-lane="${epic}"]`);
}

it('collapses one lane on a click and stores its epic id for the project', async () => {
    await mount('epic-a', 'epic-b');

    lane('epic-a').querySelector('button').click();

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        true,
    );
    expect(
        lane('epic-a').querySelector('button').getAttribute('aria-expanded'),
    ).toBe('false');
    expect(lane('epic-b').classList.contains('lp-board-lane--collapsed')).toBe(
        false,
    );
    expect(JSON.parse(window.localStorage.getItem(KEY))).toEqual(['epic-a']);
});

it('expands a collapsed lane on a second click and forgets it', async () => {
    await mount('epic-a');

    lane('epic-a').querySelector('button').click();
    lane('epic-a').querySelector('button').click();

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        false,
    );
    expect(
        lane('epic-a').querySelector('button').getAttribute('aria-expanded'),
    ).toBe('true');
    expect(JSON.parse(window.localStorage.getItem(KEY))).toEqual([]);
});

it('restores the collapsed state when a stream draws the lane again', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-b']));

    await mount('epic-a', 'epic-b');

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        false,
    );
    expect(lane('epic-b').classList.contains('lp-board-lane--collapsed')).toBe(
        true,
    );
    expect(
        lane('epic-b').querySelector('button').getAttribute('aria-expanded'),
    ).toBe('false');
});

it('keeps each project apart', async () => {
    window.localStorage.setItem(
        'loupe.board.collapsed-lanes.project-2',
        JSON.stringify(['epic-a']),
    );

    await mount('epic-a');

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        false,
    );
});

it('reads a damaged stored value as no collapsed lane', async () => {
    window.localStorage.setItem(KEY, '{not json');

    await mount('epic-a');
    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        false,
    );

    lane('epic-a').querySelector('button').click();
    expect(JSON.parse(window.localStorage.getItem(KEY))).toEqual(['epic-a']);
});

// A frame morph keeps the element, so Stimulus does not connect it again.
function morph(element) {
    element.dispatchEvent(
        new CustomEvent('turbo:morph-element', { bubbles: true }),
    );
}

function resetToServerMarkup(element) {
    element.className = '';
    element.querySelector('button').setAttribute('aria-expanded', 'true');
}

it('collapses the lane again after a morph resets it to the server markup', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    await mount('epic-a');

    resetToServerMarkup(lane('epic-a'));
    morph(lane('epic-a'));

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        true,
    );
    expect(
        lane('epic-a').querySelector('button').getAttribute('aria-expanded'),
    ).toBe('false');
});

it('ignores a morph event that bubbles from a child of the lane', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    await mount('epic-a');

    resetToServerMarkup(lane('epic-a'));
    morph(lane('epic-a').querySelector('.cells'));

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        false,
    );
});

it('collapses a lane on the first click when it shows expanded with a stored collapse', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    await mount('epic-a');
    resetToServerMarkup(lane('epic-a'));

    lane('epic-a').querySelector('button').click();

    expect(lane('epic-a').classList.contains('lp-board-lane--collapsed')).toBe(
        true,
    );
    expect(JSON.parse(window.localStorage.getItem(KEY))).toEqual(['epic-a']);
});

it('stops listening for morphs when the lane disconnects', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    await mount('epic-a');
    const element = lane('epic-a');

    element.remove();
    await new Promise((resolve) => setTimeout(resolve, 0));
    resetToServerMarkup(element);
    morph(element);

    expect(element.classList.contains('lp-board-lane--collapsed')).toBe(false);
});
