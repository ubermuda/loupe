import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

const MARK = '.lp-ref';
const HIDE_DELAY = 150;

/**
 * Reminds the reader what a mentioned ID, such as R3, stands for. The popover
 * sits outside the prose, because comment anchors count the prose's text.
 */
export default class extends Controller {
    static targets = ['popover', 'text', 'source', 'link'];
    static values = { definitions: Object, from: String };

    connect() {
        this.mark = null;
        this.pointerType = 'mouse';
        this.hideTimer = null;
        this.shownAtPointerDown = null;
        this.onPointerDown = (event) => {
            this.pointerType = event.pointerType;
            // A tap focuses the mark before its click, which shows the popover.
            this.shownAtPointerDown = this.mark;
        };
        this.onPointerOver = (event) => {
            if (event.pointerType === 'touch') {
                return;
            }
            if (this.popoverTarget.contains(event.target)) {
                this.#cancelHide();
                return;
            }
            const mark = this.#markFor(event.target);
            if (mark !== null) {
                this.#show(mark);
            }
        };
        this.onPointerOut = (event) => {
            if (event.pointerType === 'touch' || this.mark === null) {
                return;
            }
            const leaving =
                this.#markFor(event.target) === this.mark ||
                this.popoverTarget.contains(event.target);
            const staying =
                event.relatedTarget instanceof Node &&
                (this.mark.contains(event.relatedTarget) ||
                    this.popoverTarget.contains(event.relatedTarget));
            if (leaving && !staying) {
                this.#scheduleHide();
            }
        };
        this.onFocusIn = (event) => {
            const mark = this.#markFor(event.target);
            if (mark !== null) {
                this.#show(mark);
            } else if (this.popoverTarget.contains(event.target)) {
                this.#cancelHide();
            }
        };
        this.onFocusOut = (event) => {
            const staying =
                event.relatedTarget instanceof Node &&
                (this.popoverTarget.contains(event.relatedTarget) ||
                    this.mark?.contains(event.relatedTarget));
            if (this.mark !== null && !staying) {
                this.#scheduleHide();
            }
        };
        this.onClick = (event) => {
            const mark = this.#markFor(event.target);
            if (mark === null) {
                if (
                    this.mark !== null &&
                    !this.popoverTarget.contains(event.target)
                ) {
                    this.hide();
                }
                return;
            }
            const selection = window.getSelection();
            if (selection !== null && !selection.isCollapsed) {
                return;
            }
            if (
                this.pointerType === 'touch' &&
                this.shownAtPointerDown !== mark
            ) {
                event.preventDefault();
                this.#show(mark);
                return;
            }
            this.#go(mark);
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.mark !== null) {
                this.hide();
                return;
            }
            const mark = this.#markFor(event.target);
            if (event.key === 'Enter' && mark !== null) {
                event.preventDefault();
                this.#go(mark);
            }
        };
        this.onBeforeCache = () => this.hide();

        this.element.addEventListener('pointerdown', this.onPointerDown);
        this.element.addEventListener('pointerover', this.onPointerOver);
        this.element.addEventListener('pointerout', this.onPointerOut);
        this.element.addEventListener('focusin', this.onFocusIn);
        this.element.addEventListener('focusout', this.onFocusOut);
        document.addEventListener('click', this.onClick);
        this.element.addEventListener('keydown', this.onKeydown);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        this.hide();
        this.element.removeEventListener('pointerdown', this.onPointerDown);
        this.element.removeEventListener('pointerover', this.onPointerOver);
        this.element.removeEventListener('pointerout', this.onPointerOut);
        this.element.removeEventListener('focusin', this.onFocusIn);
        this.element.removeEventListener('focusout', this.onFocusOut);
        document.removeEventListener('click', this.onClick);
        this.element.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    hide() {
        this.#cancelHide();
        this.mark?.removeAttribute('aria-describedby');
        this.mark = null;
        this.popoverTarget.hidden = true;
    }

    #markFor(target) {
        const mark = target instanceof Element ? target.closest(MARK) : null;
        const pane = this.element.querySelector(
            '[data-comment-anchor-target="doc"]',
        );

        return mark !== null && pane?.contains(mark) ? mark : null;
    }

    #show(mark) {
        this.#cancelHide();
        const definition = this.definitionsValue[mark.dataset.ref];
        if (definition === undefined) {
            return;
        }
        if (this.mark !== mark) {
            this.mark?.removeAttribute('aria-describedby');
        }
        this.mark = mark;
        this.textTarget.textContent = definition.text;
        this.sourceTarget.hidden = definition.source === null;
        this.sourceTarget.textContent =
            definition.source === null
                ? ''
                : this.fromValue.replace('%title%', definition.source);
        this.linkTarget.href = definition.href;
        this.popoverTarget.hidden = false;
        mark.setAttribute('aria-describedby', this.popoverTarget.id);
        this.#place(mark);
    }

    #place(mark) {
        const frame = this.element.getBoundingClientRect();
        const anchor = mark.getBoundingClientRect();
        const width = this.popoverTarget.offsetWidth;
        const gutter = 8;
        const left = Math.max(
            gutter,
            Math.min(anchor.left, window.innerWidth - width - gutter),
        );
        this.popoverTarget.style.left = `${left - frame.left}px`;
        const height = this.popoverTarget.offsetHeight;
        const below = anchor.bottom + 4;
        const above = anchor.top - 4 - height;
        const top =
            below + height > window.innerHeight - gutter && above >= gutter
                ? above
                : below;
        this.popoverTarget.style.top = `${top - frame.top}px`;
    }

    #scheduleHide() {
        this.#cancelHide();
        this.hideTimer = window.setTimeout(() => this.hide(), HIDE_DELAY);
    }

    #cancelHide() {
        if (this.hideTimer !== null) {
            window.clearTimeout(this.hideTimer);
            this.hideTimer = null;
        }
    }

    #go(mark) {
        const definition = this.definitionsValue[mark.dataset.ref];
        if (definition === undefined) {
            return;
        }
        this.hide();
        if (definition.href.startsWith('#')) {
            window.location.hash = definition.href;
        } else {
            visit(definition.href);
        }
    }
}
