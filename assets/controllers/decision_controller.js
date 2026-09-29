import { Controller } from '@hotwired/stimulus';

const NOTE_DELAY = 800;
const STREAM_TYPE = 'text/vnd.turbo-stream.html';

// A save can answer after a visit, and its stream targets ids every review
// page shares. Only the page that sent it may render it.
document.addEventListener('turbo:before-stream-render', (event) => {
    const sender = event.target.dataset?.decisionPage;
    if (sender === undefined) return;
    const page = document.querySelector('[data-controller~="decision"]');
    if (page?.dataset.decisionPage !== sender) event.preventDefault();
});

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
        this.beforeVisit = () => this.saveWaitingNotes();
        this.beforeCache = () => this.keepDrafts();
        document.addEventListener('turbo:before-visit', this.beforeVisit);
        document.addEventListener('turbo:before-cache', this.beforeCache);
        for (const block of this.element.querySelectorAll(
            'fieldset[data-decision-id]',
        )) {
            this.decorate(block);
        }
    }

    disconnect() {
        document.removeEventListener('turbo:before-visit', this.beforeVisit);
        document.removeEventListener('turbo:before-cache', this.beforeCache);
        for (const timer of this.timers.values()) clearTimeout(timer);
    }

    // The frame lets the save in flight finish after a visit. A save still
    // queued behind it is lost, because nothing submits it once this page goes.
    saveWaitingNotes() {
        for (const block of [...this.timers.keys()]) this.saveIfChanged(block);
    }

    // The snapshot rebuilds each note from `data-decision-note`, the last
    // confirmed save. A note typed since then rides along as a draft.
    keepDrafts() {
        for (const block of this.element.querySelectorAll(
            'fieldset[data-decision-id]',
        )) {
            const field = block.querySelector('[data-decision-note-field]');
            if (field && field.value !== (block.dataset.decisionNote ?? ''))
                block.dataset.decisionNoteDraft = field.value;
            else delete block.dataset.decisionNoteDraft;
        }
    }

    decorate(block) {
        block.querySelector('[data-decision-note-controls]')?.remove();
        const editable = this.hasFormTarget;
        const draft = block.dataset.decisionNoteDraft;
        delete block.dataset.decisionNoteDraft;
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
        if (draft === undefined || draft === note) return;
        field.value = draft;
        this.schedule(block);
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

    // The form sits in its own frame, so a save never cancels a Drive visit or
    // another form. Turbo loads any HTML response into that frame, and one
    // without the frame, such as the login page, replaces the form. Every
    // response that is not a stream is a failed save.
    inspect(event) {
        if (!this.isStream(event.detail.fetchResponse)) event.preventDefault();
    }

    saved(event) {
        if (!this.inFlight) return;
        const { block, state } = this.inFlight;
        this.inFlight = null;
        if (event.detail.success && this.isStream(event.detail.fetchResponse)) {
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
