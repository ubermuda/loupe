import { Controller } from '@hotwired/stimulus';

/**
 * Collapses one epic lane of the board to its header.
 *
 * Collapse belongs to the browser, so the collapsed epic ids live in
 * localStorage, one list per project. The class is applied on connect, so a
 * board that a Turbo stream draws again keeps the lanes the reader collapsed.
 */
export default class extends Controller {
    static targets = ['toggle'];
    static values = { project: String, epic: String };

    connect() {
        this.render(this.collapsedIds().includes(this.epicValue));
    }

    toggle() {
        const ids = new Set(this.collapsedIds());
        const collapsed = !ids.has(this.epicValue);
        if (collapsed) {
            ids.add(this.epicValue);
        } else {
            ids.delete(this.epicValue);
        }
        this.store([...ids]);
        this.render(collapsed);
    }

    render(collapsed) {
        this.element.classList.toggle('lp-board-lane--collapsed', collapsed);
        if (this.hasToggleTarget) {
            this.toggleTarget.setAttribute('aria-expanded', String(!collapsed));
        }
    }

    get storageKey() {
        return `loupe.board.collapsed-lanes.${this.projectValue}`;
    }

    collapsedIds() {
        try {
            const stored = JSON.parse(
                window.localStorage.getItem(this.storageKey) ?? '[]',
            );

            return Array.isArray(stored)
                ? stored.filter((id) => typeof id === 'string')
                : [];
        } catch {
            return [];
        }
    }

    store(ids) {
        try {
            window.localStorage.setItem(this.storageKey, JSON.stringify(ids));
        } catch {
            // Storage can be blocked. The lane still collapses on this page.
        }
    }
}
