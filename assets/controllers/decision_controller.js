import { Controller } from '@hotwired/stimulus';

const NOTE_DELAY = 800;
const STREAM_TYPE = 'text/vnd.turbo-stream.html';

// Every control built here is an element with no text node: comment anchors
// count each text node in the pane, so a note lives in `.value` and a label in
// an attribute.
export default class extends Controller {
    static targets = [
        'form',
        'decisionId',
        'versionNumber',
        'options',
        'note',
        'clear',
    ];
    static values = {
        noteLabel: String,
        notePlaceholder: String,
        clearLabel: String,
        errorMessage: String,
    };

    connect() {
        this.queue = new Map();
        this.sentStates = new Map();
        this.timers = new Map();
        this.inFlight = null;
        for (const block of this.element.querySelectorAll(
            'fieldset[data-decision-id]',
        )) {
            this.decorate(block);
        }
    }

    disconnect() {
        for (const timer of this.timers.values()) clearTimeout(timer);
    }

    decorate(block) {
        block.querySelector('[data-decision-note-controls]')?.remove();
        const editable = this.hasFormTarget;
        const note = block.dataset.decisionNote ?? '';
        if (!editable && note === '') return;

        const controls = document.createElement('div');
        controls.className = 'lp-decision__note';
        controls.dataset.decisionNoteControls = '';
        const field = document.createElement('textarea');
        field.className = 'lp-input lp-decision__note-field';
        field.rows = 2;
        field.value = note;
        field.setAttribute('aria-label', this.noteLabelValue);
        field.dataset.decisionNoteField = '';
        controls.append(field);

        if (!editable) {
            field.readOnly = true;
            block.append(controls);
            return;
        }

        field.setAttribute('placeholder', this.notePlaceholderValue);
        field.addEventListener('input', () => this.schedule(block));
        field.addEventListener('blur', () => this.saveIfChanged(block));
        const clear = document.createElement('input');
        clear.type = 'button';
        clear.value = this.clearLabelValue;
        clear.className = 'lp-btn lp-btn--ghost lp-btn--sm lp-decision__clear';
        clear.addEventListener('click', () => this.clear(block));
        controls.append(clear);
        block.append(controls);
        this.sentStates.set(block, this.stateKey(this.state(block)));
    }

    // Clear empties the block at once, so a save queued behind it reads no
    // stale picks. A failed Clear puts the old answer back, so a repeat click
    // must not queue a second Clear whose snapshot is the empty block.
    clear(block) {
        const previous = this.state(block);
        const empty = previous.indexes.length === 0 && previous.note === '';
        if (empty && this.clearPending(block)) return;
        this.fillBlock(block, { indexes: [], note: '' });
        this.enqueue(block, true, previous);
    }

    clearPending(block) {
        if (this.queue.has(block)) return this.queue.get(block).clear;
        return this.inFlight?.block === block && this.inFlight.state.clear;
    }

    fillBlock(block, { indexes, note }) {
        for (const input of block.querySelectorAll(
            'input[data-decision-option]',
        ))
            input.checked = indexes.includes(Number(input.value));
        const field = block.querySelector('[data-decision-note-field]');
        if (field) field.value = note;
    }

    select(event) {
        if (!this.hasFormTarget) return;
        if (!event.target.matches('input[data-decision-option]')) return;
        const block = event.target.closest('fieldset[data-decision-id]');
        if (block) this.enqueue(block, false);
    }

    schedule(block) {
        clearTimeout(this.timers.get(block));
        this.timers.set(
            block,
            setTimeout(() => this.saveIfChanged(block), NOTE_DELAY),
        );
    }

    // A block has an edit waiting for its timer while `timers` holds it.
    saveIfChanged(block) {
        clearTimeout(this.timers.get(block));
        this.timers.delete(block);
        const state = this.state(block);
        if (this.stateKey(state) === this.sentStates.get(block)) return;
        this.enqueue(block, false);
    }

    state(block) {
        return {
            indexes: [
                ...block.querySelectorAll(
                    'input[data-decision-option]:checked',
                ),
            ].map((input) => Number(input.value)),
            note:
                block.querySelector('[data-decision-note-field]')?.value ?? '',
        };
    }

    stateKey(state) {
        return JSON.stringify(state);
    }

    // A Map keeps the first position of a block and the newest state for it.
    enqueue(block, clear, previous = null) {
        clearTimeout(this.timers.get(block));
        this.timers.delete(block);
        const state = this.state(block);
        this.sentStates.set(block, this.stateKey(state));
        this.queue.set(block, { ...state, clear, previous });
        this.flush();
    }

    flush() {
        if (this.inFlight || this.queue.size === 0) return;
        const [block, state] = this.queue.entries().next().value;
        this.queue.delete(block);
        this.inFlight = { block, state };
        this.decisionIdTarget.value = block.dataset.decisionId;
        this.fill(this.optionsTarget, state.indexes);
        this.noteTarget.value = state.note;
        this.clearTarget.checked = state.clear;
        this.formTarget.requestSubmit();
    }

    fill(target, indexes) {
        target.replaceChildren(
            ...indexes.map((value, index) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = target.dataset.fieldName + '[' + index + ']';
                input.value = value;
                return input;
            }),
        );
    }

    // Turbo renders a failed HTML response as the whole page. A save that fails
    // must leave the page, and the typed note, where they are.
    inspect(event) {
        const response = event.detail.fetchResponse;
        if (!response.succeeded && !this.isStream(response)) {
            event.preventDefault();
        }
    }

    saved(event) {
        if (!this.inFlight) return;
        const { block, state } = this.inFlight;
        this.inFlight = null;
        if (event.detail.success) {
            // A Turbo snapshot restore rebuilds the note from this attribute.
            block.dataset.decisionNote = state.note;
        } else {
            this.sentStates.delete(block);
            const edited = this.queue.has(block) || this.timers.has(block);
            if (state.previous && !edited)
                this.fillBlock(block, state.previous);
            if (!this.isStream(event.detail.fetchResponse)) this.showError();
        }
        this.flush();
    }

    isStream(response) {
        return Boolean(response?.contentType?.startsWith(STREAM_TYPE));
    }

    showError() {
        const status = document.getElementById('decision-status');
        if (!status) return;
        const message = document.createElement('span');
        message.className =
            'lp-decision-status__message lp-decision-status__message--failed';
        message.textContent = this.errorMessageValue;
        status.replaceChildren(message);
    }
}
