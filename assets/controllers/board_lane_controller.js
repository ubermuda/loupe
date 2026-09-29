import { Controller } from '@hotwired/stimulus';

/** How long the head parts glide, and how long CSS animates the rest. */
const GLIDE_MILLISECONDS = 340;
const ANIMATION_MILLISECONDS = 400;

/**
 * Collapses one epic lane of the board to a slim bar.
 *
 * Collapse belongs to the browser, so the collapsed epic ids live in
 * localStorage, one list per project. The class is applied on connect, and
 * again after a frame morph, which keeps this element and resets its class.
 * Only a click animates: CSS moves the head, the deck and the cells, and the
 * number, title and progress glide from where they stood.
 */
export default class extends Controller {
    static targets = ['toggle', 'glide'];
    static values = { project: String, epic: String };

    connect() {
        this.onReveal = () => this.render(this.isCollapsed());
        this.onMorph = (event) => {
            if (event.target === this.element) {
                this.restore();
            }
        };
        this.element.addEventListener('board-filter:reveal', this.onReveal);
        this.element.addEventListener('turbo:morph-element', this.onMorph);
        this.restore();
    }

    disconnect() {
        clearTimeout(this.animationTimer);
        this.element.removeEventListener('board-filter:reveal', this.onReveal);
        this.element.removeEventListener('turbo:morph-element', this.onMorph);
    }

    restore() {
        this.render(this.collapsedIds().includes(this.epicValue));
    }

    /**
     * A search can show the cells of a collapsed lane. A click then hides
     * them again and keeps the stored collapse; the next search change
     * shows them once more.
     */
    toggle() {
        this.animate(() => {
            const revealed = this.isRevealed();
            this.element.classList.remove('lp-board-lane--revealed');
            if (revealed && this.isCollapsed()) {
                this.render(true);

                return;
            }

            const ids = new Set(this.collapsedIds());
            const collapsed = !this.isCollapsed();
            if (collapsed) {
                ids.add(this.epicValue);
            } else {
                ids.delete(this.epicValue);
            }
            this.store([...ids]);
            this.render(collapsed);
        });
    }

    /** Runs the change, then glides each head part from its old place to its new one. */
    animate(change) {
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            change();

            return;
        }
        const parts = this.glideTargets;
        const before = parts.map((part) => part.getBoundingClientRect());
        this.element.classList.add('lp-board-lane--animating');
        change();
        parts.forEach((part, index) => {
            const after = part.getBoundingClientRect();
            const x = before[index].left - after.left;
            const y = before[index].top - after.top;
            if ((x !== 0 || y !== 0) && typeof part.animate === 'function') {
                part.animate(
                    [
                        { transform: `translate(${x}px, ${y}px)` },
                        { transform: 'none' },
                    ],
                    {
                        duration: GLIDE_MILLISECONDS,
                        easing: 'cubic-bezier(0.3, 0.8, 0.2, 1)',
                    },
                );
            }
        });
        clearTimeout(this.animationTimer);
        this.animationTimer = setTimeout(
            () => this.element.classList.remove('lp-board-lane--animating'),
            ANIMATION_MILLISECONDS,
        );
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
