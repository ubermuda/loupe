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
 *     <div data-drawer-target="content">
 *       <button data-drawer-target="trigger" data-action="click->drawer#toggle"
 *               aria-expanded="false"> ... </button>
 *     </div>
 *   </div>
 */
const OPEN_CLASS = 'lp-sidebar--open';
/** Tailwind's `lg`, where app.css puts the sidebar back in the flow. */
const DESKTOP_QUERY = '(min-width: 64rem)';

export default class extends Controller {
    static targets = ['trigger', 'panel', 'scrim', 'dismiss', 'content'];

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
        // A window that grows past lg hides the scrim and both close controls,
        // and would strand the shell inert with nothing left to release it.
        this.desktopQuery = window.matchMedia(DESKTOP_QUERY);
        this.onDesktop = (event) => {
            if (event.matches) {
                this.#reset();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.desktopQuery.addEventListener('change', this.onDesktop);
        this.#reset();
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        this.desktopQuery.removeEventListener('change', this.onDesktop);
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
        // The scrim stops a tap on what it covers; `inert` stops Tab reaching
        // the same controls, which is the half a scrim cannot do.
        if (this.hasContentTarget) {
            this.contentTarget.inert = true;
        }
        // Safe in this tick only because app.css flips the panel's `visibility`
        // with no delay on the way in. A `visibility: hidden` element refuses
        // focus and reports no error.
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

    // Clears `inert` before close() restores focus, or the element it aims at
    // is still unfocusable.
    #reset() {
        this.previouslyFocused = null;
        this.panelTarget.classList.remove(OPEN_CLASS);
        this.#setExpanded(false);
        if (this.hasScrimTarget) {
            this.scrimTarget.hidden = true;
        }
        if (this.hasContentTarget) {
            this.contentTarget.inert = false;
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
