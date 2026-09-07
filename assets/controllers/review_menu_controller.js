import { Controller } from '@hotwired/stimulus';

/**
 * The review screen's mobile menu: a round button in the bottom right, and a
 * panel that opens upward from it. Contents, Versions, References and Decisions
 * drill down inside the same panel, and the button becomes the way back, so the
 * exit stays under the thumb. Leaf actions act and close.
 */
const DESKTOP_QUERY = '(min-width: 64rem)';

export default class extends Controller {
    static targets = [
        'trigger',
        'panel',
        'scrim',
        'root',
        'view',
        'iconMenu',
        'iconClose',
        'iconBack',
    ];

    connect() {
        this.openerRow = null;
        // Capture, because the drawer's own trigger stops the click before it
        // reaches a bubbling document listener.
        this.onDocumentClick = (event) => {
            if (this.#isOpen() && !this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.close();
                return;
            }
            if (event.key === 'Tab' && this.#isOpen()) {
                this.#trapTab(event);
            }
        };
        // A long list scrolls inside the panel, and that scroll is not the
        // reader leaving the menu.
        this.onScroll = (event) => {
            if (this.#isOpen() && !this.panelTarget.contains(event.target)) {
                this.close();
            }
        };
        this.onBeforeCache = () => this.#reset();
        this.desktopQuery = window.matchMedia(DESKTOP_QUERY);
        this.onDesktop = (event) => {
            if (event.matches) {
                this.#reset();
            }
        };
        // The resolved toggle is revealed by another controller once a thread is
        // resolved, so the button has to appear and disappear with it.
        this.presenceObserver = new MutationObserver(() =>
            this.#syncPresence(),
        );
        this.presenceObserver.observe(this.rootTarget, {
            attributes: true,
            attributeFilter: ['hidden'],
            subtree: true,
        });
        document.addEventListener('click', this.onDocumentClick, true);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('scroll', this.onScroll, true);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.desktopQuery.addEventListener('change', this.onDesktop);
        this.#reset();
        this.#syncPresence();
    }

    disconnect() {
        this.presenceObserver.disconnect();
        document.removeEventListener('click', this.onDocumentClick, true);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('scroll', this.onScroll, true);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        this.desktopQuery.removeEventListener('change', this.onDesktop);
    }

    /** The one button is open, back and close, in that order of state. */
    toggle(event) {
        event.preventDefault();
        if (!this.#isOpen()) {
            this.open();
        } else if (this.#openView() !== undefined) {
            this.back();
        } else {
            this.close();
        }
    }

    open() {
        this.panelTarget.hidden = false;
        if (this.hasScrimTarget) {
            this.scrimTarget.hidden = false;
        }
        this.#showRoot();
        this.#syncTrigger();
        this.#focusFirstIn(this.rootTarget);
    }

    close() {
        if (!this.#isOpen()) {
            return;
        }
        this.#reset();
        // The trigger is the menu's only anchor, so it is where focus belongs
        // whichever way the panel was dismissed.
        this.triggerTarget.focus({ preventScroll: true });
    }

    /** Swaps the panel's rows for one of the lists, in place. */
    drill(event) {
        event.preventDefault();
        const view = this.#viewFor(event.params.view);
        if (view === undefined) {
            return;
        }
        this.openerRow = event.currentTarget;
        this.rootTarget.hidden = true;
        for (const candidate of this.viewTargets) {
            candidate.hidden = candidate !== view;
        }
        this.#syncTrigger();
        this.#focusFirstIn(view);
    }

    back() {
        const opener = this.openerRow;
        this.#showRoot();
        this.#syncTrigger();
        if (opener instanceof HTMLElement && opener.isConnected) {
            opener.focus({ preventScroll: true });
        } else {
            this.#focusFirstIn(this.rootTarget);
        }
    }

    /**
     * Jumps to a heading the menu links to. The paper scrolls inside .lp-main
     * rather than the window, so the jump has to name an element and let
     * scrollIntoView find the scroller.
     */
    jump(event) {
        const id = event.currentTarget.getAttribute('href')?.slice(1);
        const target = id === undefined ? null : document.getElementById(id);
        event.preventDefault();
        this.close();
        target?.scrollIntoView({ block: 'start' });
    }

    #reset() {
        this.panelTarget.hidden = true;
        if (this.hasScrimTarget) {
            this.scrimTarget.hidden = true;
        }
        this.#showRoot();
        this.#syncTrigger();
    }

    #showRoot() {
        this.openerRow = null;
        this.rootTarget.hidden = false;
        for (const view of this.viewTargets) {
            view.hidden = true;
        }
    }

    /** A button that opens an empty panel is worse than no button. */
    #syncPresence() {
        const hasRow =
            this.rootTarget.querySelector(
                '.lp-review-menu__row:not([hidden]), .lp-review-menu__verdict',
            ) !== null;
        this.element.hidden = !hasRow;
        if (!hasRow && this.#isOpen()) {
            this.#reset();
        }
    }

    #isOpen() {
        return !this.panelTarget.hidden;
    }

    #openView() {
        return this.viewTargets.find((view) => !view.hidden);
    }

    #viewFor(name) {
        return this.viewTargets.find((view) => view.dataset.view === name);
    }

    /**
     * The scrim stops a tap on what it covers. This is the half a scrim cannot
     * do. The trigger closes the cycle, because it is the way out.
     */
    #trapTab(event) {
        const stops = this.#focusableIn(this.panelTarget);
        stops.push(this.triggerTarget);
        const active = document.activeElement;
        const outside = !this.element.contains(active);
        if (event.shiftKey && (outside || active === stops[0])) {
            event.preventDefault();
            stops[stops.length - 1].focus({ preventScroll: true });
        } else if (
            !event.shiftKey &&
            (outside || active === stops[stops.length - 1])
        ) {
            event.preventDefault();
            stops[0].focus({ preventScroll: true });
        }
    }

    #focusableIn(container) {
        return [
            ...container.querySelectorAll('a[href], button:not([disabled])'),
        ].filter((element) => element.closest('[hidden]') === null);
    }

    #focusFirstIn(container) {
        const [first] = this.#focusableIn(container);
        if (first === undefined) {
            this.panelTarget.focus({ preventScroll: true });
            return;
        }
        first.focus({ preventScroll: true });
    }

    /** The icon and the label follow the state, because the button is all three. */
    #syncTrigger() {
        const open = this.#isOpen();
        const drilled = this.#openView() !== undefined;
        let state = 'menu';
        if (open) {
            state = drilled ? 'back' : 'close';
        }

        this.triggerTarget.setAttribute(
            'aria-expanded',
            open ? 'true' : 'false',
        );
        this.iconMenuTarget.hidden = state !== 'menu';
        this.iconCloseTarget.hidden = state !== 'close';
        this.iconBackTarget.hidden = state !== 'back';

        const labels = this.triggerTarget.dataset;
        const label = {
            menu: labels.labelMenu,
            close: labels.labelClose,
            back: labels.labelBack,
        }[state];
        if (label !== undefined) {
            this.triggerTarget.setAttribute('aria-label', label);
        }
    }
}
