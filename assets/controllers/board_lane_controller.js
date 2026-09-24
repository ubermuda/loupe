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
        this.onReveal = () => this.render(this.isCollapsed());
        this.element.addEventListener('board-filter:reveal', this.onReveal);
        this.render(this.collapsedIds().includes(this.epicValue));
    }

    disconnect() {
        this.element.removeEventListener('board-filter:reveal', this.onReveal);
    }

    /**
     * A search can show the cells of a collapsed lane. A click then hides
     * them again and keeps the stored collapse; the next search change
     * shows them once more.
     */
    toggle() {
        const revealed = this.isRevealed();
        this.element.classList.remove('lp-board-lane--revealed');
        if (revealed && this.isCollapsed()) {
            this.render(true);

            return;
        }

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
            this.toggleTarget.setAttribute(
                'aria-expanded',
                String(!collapsed || this.isRevealed()),
            );
        }
    }

    isCollapsed() {
        return this.element.classList.contains('lp-board-lane--collapsed');
    }

    isRevealed() {
        return this.element.classList.contains('lp-board-lane--revealed');
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
