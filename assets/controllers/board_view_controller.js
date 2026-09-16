import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['board', 'list', 'boardButton', 'listButton'];

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
