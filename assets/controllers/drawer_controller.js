import { Controller } from '@hotwired/stimulus';

const OPEN_CLASS = 'lp-sidebar--open';
const DESKTOP_QUERY = '(width > 48.75rem)';

export default class extends Controller {
    static targets = ['trigger', 'panel', 'scrim', 'dismiss', 'content'];

    connect() {
        this.previouslyFocused = null;
        this.lastFocused = document.activeElement;
        this.onFocus = (event) => {
            this.lastFocused = event.target;
        };
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
        this.desktopQuery = window.matchMedia(DESKTOP_QUERY);
        this.onDesktop = (event) => {
            const focused =
                document.activeElement === document.body
                    ? this.lastFocused
                    : document.activeElement;
            const focusInPanel = this.panelTarget.contains(focused);
            const focusOnDismiss =
                this.hasDismissTarget && focused === this.dismissTarget;
            const focusOnTrigger =
                this.hasTriggerTarget && focused === this.triggerTarget;
            this.#reset();
            if (!event.matches && focusInPanel && this.hasTriggerTarget) {
                this.triggerTarget.focus();
            } else if (event.matches && (focusOnDismiss || focusOnTrigger)) {
                this.panelTarget.querySelector('a[href]')?.focus();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('focusin', this.onFocus);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.desktopQuery.addEventListener('change', this.onDesktop);
        this.#reset();
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('focusin', this.onFocus);
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
