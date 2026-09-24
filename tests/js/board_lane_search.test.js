/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BoardFilterController from '../../assets/controllers/board_filter_controller.js';
import BoardLaneController from '../../assets/controllers/board_lane_controller.js';

const KEY = 'loupe.board.collapsed-lanes.project-1';

let application;

beforeEach(() => {
    window.localStorage.clear();
    application = Application.start();
    application.register('board-filter', BoardFilterController);
    application.register('board-lane', BoardLaneController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
    window.localStorage.clear();
});

async function mount() {
    document.body.innerHTML = `<div data-controller="board-filter">
        <input data-board-filter-target="query" data-action="input->board-filter#filter">
        <span data-board-filter-target="count" data-one="1 card" data-many="%count% cards">1 card</span>
        <p data-board-filter-target="empty" hidden>No card matches.</p>
        <section class="lp-board-lane" data-lane="epic-a"
                 data-controller="board-lane"
                 data-board-lane-project-value="project-1"
                 data-board-lane-epic-value="epic-a">
            <button type="button" aria-expanded="true"
                    data-board-lane-target="toggle"
                    data-action="board-lane#toggle">Toggle</button>
            <article data-board-filter-target="card" data-card-title="Pay with a card">Pay</article>
        </section>
    </div>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
}

function search(text) {
    const query = document.querySelector('[data-board-filter-target="query"]');
    query.value = text;
    query.dispatchEvent(new Event('input', { bubbles: true }));
}

const lane = () => document.querySelector('[data-lane="epic-a"]');
const button = () => lane().querySelector('button');
const stored = () => JSON.parse(window.localStorage.getItem(KEY));
const revealed = () => lane().classList.contains('lp-board-lane--revealed');

it('reads as expanded while a search reveals a collapsed lane', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    await mount();
    expect(button().getAttribute('aria-expanded')).toBe('false');

    search('pay');

    expect(revealed()).toBe(true);
    expect(button().getAttribute('aria-expanded')).toBe('true');
});

it('hides a revealed lane on a click and keeps it stored as collapsed', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    await mount();
    search('pay');

    button().click();

    expect(revealed()).toBe(false);
    expect(lane().classList.contains('lp-board-lane--collapsed')).toBe(true);
    expect(button().getAttribute('aria-expanded')).toBe('false');
    expect(stored()).toEqual(['epic-a']);

    search('pay w');
    expect(revealed()).toBe(true);
    expect(button().getAttribute('aria-expanded')).toBe('true');
});

it('collapses an expanded lane during a search as it does without one', async () => {
    await mount();
    search('pay');

    button().click();

    expect(revealed()).toBe(false);
    expect(lane().classList.contains('lp-board-lane--collapsed')).toBe(true);
    expect(button().getAttribute('aria-expanded')).toBe('false');
    expect(stored()).toEqual(['epic-a']);
});

it('restores the button from the stored state when the search clears', async () => {
    window.localStorage.setItem(KEY, JSON.stringify(['epic-a']));
    await mount();
    search('pay');

    search('');

    expect(revealed()).toBe(false);
    expect(lane().classList.contains('lp-board-lane--collapsed')).toBe(true);
    expect(button().getAttribute('aria-expanded')).toBe('false');
});
