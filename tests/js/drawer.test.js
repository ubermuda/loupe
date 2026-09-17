/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DrawerController from '../../assets/controllers/drawer_controller.js';

let application;
let breakpointChanged;

beforeEach(async () => {
    vi.stubGlobal('matchMedia', () => ({
        addEventListener: (_, listener) => {
            breakpointChanged = listener;
        },
        removeEventListener: vi.fn(),
    }));
    document.body.innerHTML = `<div data-controller="drawer">
        <button data-drawer-target="trigger">Open</button>
        <aside data-drawer-target="panel">
            <a href="/projects">Projects</a>
            <button data-drawer-target="dismiss">Close</button>
        </aside>
        <main data-drawer-target="content"><input></main>
    </div>`;
    application = Application.start();
    application.register('drawer', DrawerController);
    await new Promise((resolve) => setTimeout(resolve, 0));
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.unstubAllGlobals();
});

it.each(['dismiss', 'trigger'])(
    'recovers %s focus lost before the desktop media event',
    (target) => {
        const button = document.querySelector(
            `[data-drawer-target="${target}"]`,
        );
        button.focus();
        button.blur();
        expect(document.activeElement).toBe(document.body);
        breakpointChanged({ matches: true });
        expect(document.activeElement).toBe(document.querySelector('a'));
    },
);

it('recovers sidebar focus lost before the mobile media event', () => {
    const link = document.querySelector('a');
    link.focus();
    link.blur();
    breakpointChanged({ matches: false });
    expect(document.activeElement).toBe(
        document.querySelector('[data-drawer-target="trigger"]'),
    );
});

it('keeps focus in page content when the breakpoint changes', () => {
    const input = document.querySelector('input');
    document.querySelector('a').focus();
    input.focus();
    breakpointChanged({ matches: false });
    expect(document.activeElement).toBe(input);
});
