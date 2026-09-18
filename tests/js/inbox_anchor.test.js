/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it, vi } from 'vitest';
import InboxAnchorController from '../../assets/controllers/inbox_anchor_controller.js';

let application;
let replace;

async function mount(href, markup = '') {
    const url = new URL(href, 'https://loupe.test');
    replace = vi.fn();
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: { href: url.href, hash: url.hash, replace },
    });
    document.body.innerHTML =
        markup +
        '<div data-controller="inbox-anchor" data-inbox-anchor-queue-value="completed"></div>';
    application = Application.start();
    application.register('inbox-anchor', InboxAnchorController);
    await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
    application?.stop();
    document.body.replaceChildren();
});

it('keeps the page of a link whose request is not in this queue', async () => {
    await mount('/projects/p/inbox?page=2#inbox-ask-9');

    expect(replace).toHaveBeenCalledOnce();
    const target = new URL(replace.mock.calls[0][0], 'https://loupe.test');
    expect(target.searchParams.get('queue')).toBe('completed');
    expect(target.searchParams.get('page')).toBe('2');
    expect(target.hash).toBe('#inbox-ask-9');
});

it('stays put when the fragment names a request this queue holds', async () => {
    await mount(
        '/projects/p/inbox#inbox-item-4',
        '<section id="inbox-item-4"></section>',
    );

    expect(replace).not.toHaveBeenCalled();
});

it('ignores a fragment that names no request', async () => {
    await mount('/projects/p/inbox#main-content');

    expect(replace).not.toHaveBeenCalled();
});
