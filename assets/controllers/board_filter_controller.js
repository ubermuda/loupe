import { Controller } from '@hotwired/stimulus';

// A board re-render replaces this element and keeps its parent, so the parent
// keys the query across a reload of the board frame or a stream replace.
const savedStates = new WeakMap();
const BEFORE_RENDER_EVENTS = [
    'turbo:before-frame-render',
    'turbo:before-stream-render',
];

export default class extends Controller {
    static targets = ['query', 'card', 'row', 'count', 'empty'];

    #restoringFocus = false;

    connect() {
        this.stateKey = this.element.parentElement;
        this.rememberFocus = () => this.#remember();
        for (const type of BEFORE_RENDER_EVENTS) {
            document.addEventListener(type, this.rememberFocus);
        }
        this.#restore();
    }

    disconnect() {
        for (const type of BEFORE_RENDER_EVENTS) {
            document.removeEventListener(type, this.rememberFocus);
        }
        if (this.stateKey !== null) {
            savedStates.set(this.stateKey, {
                focused: false,
                ...savedStates.get(this.stateKey),
                query: this.queryTarget.value,
            });
        }
    }

    revealField(event) {
        if (this.#restoringFocus) {
            return;
        }
        event.target.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
            behavior: 'instant',
        });
    }

    filter() {
        const query = this.queryTarget.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        for (const card of this.cardTargets) {
            const visible =
                query === '' ||
                card.dataset.cardTitle.toLocaleLowerCase().includes(query);

            card.hidden = !visible;
            if (visible) {
                visibleCount += 1;
            }
        }

        for (const row of this.rowTargets) {
            row.hidden =
                query !== '' &&
                !row.dataset.cardTitle.toLocaleLowerCase().includes(query);
        }

        this.countTarget.textContent =
            visibleCount === 1
                ? this.countTarget.dataset.one
                : this.countTarget.dataset.many.replace(
                      '%count%',
                      String(visibleCount),
                  );
        this.emptyTarget.hidden = visibleCount !== 0 || query === '';
    }

    // The swap removes the input before disconnect runs, so focus is read here.
    #remember() {
        if (this.stateKey === null) {
            return;
        }
        const input = this.queryTarget;
        savedStates.set(this.stateKey, {
            query: input.value,
            focused: document.activeElement === input,
            selectionStart: input.selectionStart,
            selectionEnd: input.selectionEnd,
        });
    }

    #restore() {
        const state =
            this.stateKey === null ? undefined : savedStates.get(this.stateKey);
        savedStates.delete(this.stateKey);
        if (state === undefined) {
            return;
        }
        const input = this.queryTarget;
        if (state.query !== '') {
            input.value = state.query;
            this.filter();
        }
        if (state.focused) {
            // focusin fires inside focus(), and a reveal would undo the user's scroll.
            this.#restoringFocus = true;
            try {
                input.focus({ preventScroll: true });
            } finally {
                this.#restoringFocus = false;
            }
            input.setSelectionRange(state.selectionStart, state.selectionEnd);
        }
    }
}
