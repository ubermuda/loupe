/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import BacklogLiveController from '../../assets/controllers/backlog_live_controller.js';
import { on } from '../../assets/lib/live.js';

vi.mock('../../assets/lib/live.js', () => ({ on: vi.fn(() => () => {}) }));

let application;
let change;
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(async () => {
    on.mockImplementation((types, handler) => {
        change = handler;

        return () => {};
    });
    document.body.innerHTML = `<div data-controller="backlog-live">
        <p data-backlog-live-target="notice" hidden>The Backlog changed.</p>
    </div>`;
    application = Application.start();
    application.register('backlog-live', BacklogLiveController);
    await settle();
});

afterEach(async () => {
    document.body.replaceChildren();
    await settle();
    application.stop();
    vi.clearAllMocks();
});

const notice = () => document.querySelector('[data-backlog-live-target]');

it('listens to card and column changes of the board', () => {
    expect(on).toHaveBeenCalledWith(
        ['board.card_changed', 'board.columns_changed'],
        expect.any(Function),
    );
});

it('shows the notice when someone else changes the board', () => {
    change({ type: 'board.card_changed', cardId: 'a', own: false });

    expect(notice().hidden).toBe(false);
});

it('ignores a change this page made', () => {
    change({ type: 'board.card_changed', cardId: 'a', own: true });

    expect(notice().hidden).toBe(true);
});
