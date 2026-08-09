import { Controller } from '@hotwired/stimulus';

/*
 * Toggles a panel anchored to a trigger — the shell's project switcher today.
 * Closes on Escape and on any click outside the controller's own element, so a
 * panel can never be left open behind the thing the reviewer clicked next.
 *
 * Usage:
 *   <div data-controller="popover">
 *     <button data-action="click->popover#toggle"
 *             data-popover-target="trigger" aria-expanded="false"> ... </button>
 *     <div data-popover-target="panel" hidden> ... </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['trigger', 'panel'];

    connect() {
        // Bound on the document rather than the element: a click that lands
        // outside never reaches an element-scoped action, which is the whole
        // case this has to catch.
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
    }

    toggle(event) {
        event.preventDefault();
        // Without this the click continues to the document listener above,
        // which would immediately close what this call just opened.
        event.stopPropagation();
        if (this.panelTarget.hidden) {
            this.open();
        } else {
            this.close();
        }
    }

    open() {
        this.panelTarget.hidden = false;
        this.#setExpanded(true);
    }

    close() {
        if (this.panelTarget.hidden) {
            return;
        }
        this.panelTarget.hidden = true;
        this.#setExpanded(false);
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
