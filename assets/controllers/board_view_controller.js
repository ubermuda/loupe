import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['board', 'list', 'boardButton', 'listButton'];

    connect() {
        this.restore();
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
