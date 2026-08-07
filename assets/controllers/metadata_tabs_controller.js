import { Controller } from '@hotwired/stimulus';

/*
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

    toggle(event) {
        event.preventDefault();
        const name = event.params.panel;
        const wasOpen = this.#isOpen(name);
        this.closeAll();
        if (!wasOpen) {
            this.#open(name);
        }
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
