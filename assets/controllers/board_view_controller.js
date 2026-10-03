import { Controller } from '@hotwired/stimulus';

// A board re-render replaces this element and keeps its parent, so the parent
// keys the chosen view across a stream replace.
const savedViews = new WeakMap();

export default class extends Controller {
    static targets = ['board', 'list', 'boardButton', 'listButton'];
    static values = { listUrl: String };

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
        // The list frame loads only while shown. Turbo rewrites src to an absolute URL, so presence is the test.
        if (board) {
            this.listTarget.removeAttribute('src');
            this.listTarget.replaceChildren();
        } else if (!this.listTarget.hasAttribute('src')) {
            this.listTarget.setAttribute('src', this.listUrlValue);
        }
        this.boardButtonTarget.setAttribute('aria-pressed', String(board));
        this.listButtonTarget.setAttribute('aria-pressed', String(!board));
    }
}
