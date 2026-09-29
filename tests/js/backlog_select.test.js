/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import BacklogSelectController from '../../assets/controllers/backlog_select_controller.js';

let application;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const row = (id) => `<div id="row-${id}">
    <input type="checkbox" value="${id}" data-backlog-select-target="box" data-action="backlog-select#update">
</div>`;

beforeEach(async () => {
    document.body.innerHTML = `<div data-controller="backlog-select">
        <input type="checkbox" id="all" data-backlog-select-target="all" data-action="backlog-select#toggleAll">
        <div id="rows">${row('a')}${row('b')}${row('c')}</div>
        <form id="bar" data-backlog-select-target="bar" hidden>
            <span id="count" data-backlog-select-target="count">0</span>
            <button type="button" id="clear" data-action="backlog-select#clear">Clear</button>
        </form>
    </div>`;
    application = Application.start();
    application.register('backlog-select', BacklogSelectController);
    await settle();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
});

const box = (id) => document.querySelector(`#row-${id} input`);
const bar = () => document.getElementById('bar');
const all = () => document.getElementById('all');

it('shows the bar with the count while a row is ticked', () => {
    expect(bar().hidden).toBe(true);

    box('a').click();
    box('c').click();

    expect(bar().hidden).toBe(false);
    expect(document.getElementById('count').textContent).toBe('2');
    expect(all().checked).toBe(false);
    expect(all().indeterminate).toBe(true);

    box('a').click();
    box('c').click();

    expect(bar().hidden).toBe(true);
});

it('ticks and clears every row from the header box', () => {
    all().click();

    expect([box('a'), box('b'), box('c')].every((input) => input.checked)).toBe(
        true,
    );
    expect(document.getElementById('count').textContent).toBe('3');
    expect(all().indeterminate).toBe(false);

    all().click();

    expect([box('a'), box('b'), box('c')].some((input) => input.checked)).toBe(
        false,
    );
    expect(bar().hidden).toBe(true);
});

it('clears the selection from the bar', () => {
    box('b').click();
    document.getElementById('clear').click();

    expect(box('b').checked).toBe(false);
    expect(bar().hidden).toBe(true);
});

it('follows the rows a stream removes', async () => {
    all().click();
    document.getElementById('row-a').remove();
    document.getElementById('row-b').remove();
    await settle();

    expect(document.getElementById('count').textContent).toBe('1');
    expect(all().checked).toBe(true);

    document.getElementById('row-c').remove();
    await settle();

    expect(bar().hidden).toBe(true);
    expect(all().checked).toBe(false);
});
