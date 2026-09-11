import { Controller } from '@hotwired/stimulus';
import { smoothScrollTo } from '../lib/smooth_scroll.js';

/**
 * The document's metadata toolbar: Versions, Contents and References as three
 * mutually-exclusive panels. Opening one closes the others, so the three
 * together never cost more than one panel of height above the prose.
 *
 * Usage:
 *   <div data-controller="metadata-tabs">
 *     <button data-action="click->metadata-tabs#toggle"
 *             data-metadata-tabs-panel-param="versions"
 *             data-metadata-tabs-target="tab" aria-expanded="false"> ... </button>
 *     <div data-metadata-tabs-target="panel" data-panel="versions" hidden> ... </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    /**
     * A jump's animation outlives the visit that started it otherwise. It writes
     * scrollTop for as long as it runs, and the scroller survives a Turbo visit,
     * so the page that arrives gets dragged towards the heading the last one
     * asked for. The diff navigation controller cancels for the same reason.
     */
    disconnect() {
        this.cancelScroll?.();
        this.cancelScroll = undefined;
    }

    toggle(event) {
        event.preventDefault();
        const name = event.params.panel;
        const wasOpen = this.#isOpen(name);
        this.closeAll();
        if (!wasOpen) {
            this.#open(name);
        }
    }

    /**
     * Scrolls to a block a panel links to, and closes the panel behind it.
     *
     * Not left to the browser's own `#hash` handling: the paper scrolls inside
     * .lp-main rather than the window, so the scroll has to name an element and
     * find that scroller. scroll-margin-top on the target keeps it clear of
     * this bar, and the shared helper reads it.
     */
    jump(event) {
        const id = event.currentTarget.getAttribute('href')?.slice(1);
        const target = id === undefined ? null : document.getElementById(id);
        if (target === null) {
            return;
        }

        event.preventDefault();
        this.closeAll();
        this.cancelScroll?.();
        this.cancelScroll = smoothScrollTo(target, { align: 'start' });
    }

    /** Called by the page on navigation away, and by Escape. */
    closeAll() {
        for (const panel of this.panelTargets) {
            panel.hidden = true;
        }
        for (const tab of this.tabTargets) {
            tab.setAttribute('aria-expanded', 'false');
        }
    }

    #isOpen(name) {
        const panel = this.#panelFor(name);
        return panel !== undefined && !panel.hidden;
    }

    #open(name) {
        const panel = this.#panelFor(name);
        if (panel === undefined) {
            return;
        }
        panel.hidden = false;
        this.#tabFor(name)?.setAttribute('aria-expanded', 'true');
    }

    #panelFor(name) {
        return this.panelTargets.find((panel) => panel.dataset.panel === name);
    }

    #tabFor(name) {
        return this.tabTargets.find(
            (tab) => tab.dataset.metadataTabsPanelParam === name,
        );
    }
}
