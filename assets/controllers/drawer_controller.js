import { Controller } from '@hotwired/stimulus';

/**
 * Slides the app sidebar in from the left below the lg breakpoint, where the
 * sidebar is off-canvas. Closes on Escape, on a click outside the panel, and
 * before Turbo caches the page, so a tapped nav link cannot leave the drawer
 * open over the screen it went to.
 *
 * Usage:
 *   <div data-controller="drawer">
 *     <aside data-drawer-target="panel"> ... </aside>
 *     <div data-drawer-target="scrim" data-action="click->drawer#close" hidden></div>
 *     <button data-drawer-target="trigger" data-action="click->drawer#toggle"
 *             aria-expanded="false"> ... </button>
 *   </div>
 */
const OPEN_CLASS = 'lp-sidebar--open';

export default class extends Controller {
    static targets = ['trigger', 'panel', 'scrim', 'dismiss'];

    connect() {
        this.previouslyFocused = null;
        // Bound on the document rather than the element: the panel covers the
        // page, so the tap that means "close" lands outside every action.
        this.onDocumentClick = (event) => {
            if (this.#isOpen() && !this.panelTarget.contains(event.target)) {
                this.close();
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };
        // Turbo caches the page as it leaves it, and an open drawer in that
        // snapshot comes back open on the next restore visit.
        this.onBeforeCache = () => this.#reset();
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.#reset();
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    toggle(event) {
        event.preventDefault();
        // Without this the click continues to the document listener above,
        // which would immediately close what this call just opened.
        event.stopPropagation();
        if (this.#isOpen()) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.previouslyFocused = document.activeElement;
        this.panelTarget.classList.add(OPEN_CLASS);
        this.#setExpanded(true);
        if (this.hasScrimTarget) {
            this.scrimTarget.hidden = false;
        }
        if (this.hasDismissTarget) {
            this.dismissTarget.focus();
        }
    }

    close() {
        if (!this.#isOpen()) {
            return;
        }
        const restoreTo = this.previouslyFocused;
        this.#reset();
        if (restoreTo && restoreTo.isConnected) {
            restoreTo.focus();
        }
    }

    #reset() {
        this.previouslyFocused = null;
        this.panelTarget.classList.remove(OPEN_CLASS);
        this.#setExpanded(false);
        if (this.hasScrimTarget) {
            this.scrimTarget.hidden = true;
        }
    }

    #isOpen() {
        return this.panelTarget.classList.contains(OPEN_CLASS);
    }

    #setExpanded(expanded) {
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute(
                'aria-expanded',
                expanded ? 'true' : 'false',
            );
        }
    }
}
