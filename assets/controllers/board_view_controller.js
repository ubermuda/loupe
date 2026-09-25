import { Controller } from '@hotwired/stimulus';

// A board re-render replaces this element and keeps its parent, so the parent
// keys the chosen view across a reload of the board frame or a stream replace.
const savedViews = new WeakMap();

export default class extends Controller {
    static targets = ['board', 'list', 'boardButton', 'listButton'];

    connect() {
        this.stateKey = this.element.parentElement;
        this.restore();
        if (
            this.stateKey !== null &&
            savedViews.get(this.stateKey) === 'list'
        ) {
            this.#show('list');
        }
    }

    disconnect() {
        if (this.stateKey !== null) {
            savedViews.set(
                this.stateKey,
                this.boardTarget.hidden ? 'list' : 'board',
            );
        }
    }

    /** The mode buttons sit in a kept toolbar, so they remember the view a render resets. */
    restore() {
        const list =
            this.listButtonTarget.getAttribute('aria-pressed') === 'true';
        this.#show(list ? 'list' : 'board');
    }

    showBoard() {
        this.#show('board');
    }

    showList() {
        this.#show('list');
    }

    #show(view) {
        const board = view === 'board';

        this.boardTarget.hidden = !board;
        this.listTarget.hidden = board;
        this.boardButtonTarget.setAttribute('aria-pressed', String(board));
        this.listButtonTarget.setAttribute('aria-pressed', String(!board));
    }
}
