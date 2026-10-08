import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';

const NOTE_DELAY = 800;
const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const CONNECTED_ATTRIBUTE = 'data-decision-connected';

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
        summaryUrl: String,
        changedBy: String,
    };

    connect() {
        this.queue = new Map();
        this.sentStates = new Map();
        this.timers = new Map();
        this.inFlight = null;
        this.heldVisit = null;
        this.beforeVisit = (event) => this.holdVisit(event);
        this.beforeCache = () => this.keepDrafts();
        this.beforeUnload = (event) => {
            if (!this.pending()) return;
            event.preventDefault();
            event.returnValue = '';
        };
        document.addEventListener('turbo:before-visit', this.beforeVisit);
        document.addEventListener('turbo:before-cache', this.beforeCache);
        window.addEventListener('beforeunload', this.beforeUnload);
        for (const block of this.element.querySelectorAll(
            'fieldset[data-decision-id]',
        )) {
            this.decorate(block);
        }
        this.summaryRunning = false;
        this.summaryAgain = false;
        this.savesLanded = 0;
        this.deferred = false;
        if (this.element.dataset.decisionPage !== undefined)
            this.unsubscribe = on(
                'review.decision_changed',
                (change) => this.receive(change),
                {
                    // The hub keeps no history, so each open reads what a
                    // closed connection missed, a first open included.
                    onOpen: () => {
                        this.element.setAttribute(CONNECTED_ATTRIBUTE, '');
                        this.refreshSummary();
                    },
                    onError: () =>
                        this.element.removeAttribute(CONNECTED_ATTRIBUTE),
                },
            );
    }

    disconnect() {
        this.unsubscribe?.();
        this.element.removeAttribute(CONNECTED_ATTRIBUTE);
        document.removeEventListener('turbo:before-visit', this.beforeVisit);
        document.removeEventListener('turbo:before-cache', this.beforeCache);
        window.removeEventListener('beforeunload', this.beforeUnload);
        for (const timer of this.timers.values()) clearTimeout(timer);
    }

    // A queued save needs this page to submit it, so the visit waits for the
    // queue to drain. A failed save cancels the visit and shows its error.
    holdVisit(event) {
        for (const block of [...this.timers.keys()]) this.saveIfChanged(block);
        if (!this.pending()) return;
        event.preventDefault();
        this.heldVisit = event.detail.url;
    }

    pending() {
        return Boolean(
            this.inFlight || this.queue.size > 0 || this.timers.size > 0,
        );
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
        block.querySelector('[data-decision-clear-control]')?.remove();
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
        clear.dataset.decisionClearControl = '';
        clear.addEventListener('click', () => this.clear(block));
        // It sits in the block header beside the kind-of-answer chip, apart
        // from the note field, and is no part of the legend's text.
        block.prepend(clear);
        block.append(controls);
        const saved = this.stateKey({
            indexes: this.savedIndexes(block),
            note,
        });
        this.sentStates.set(block, saved);
        if (draft !== undefined) field.value = draft;
        if (this.stateKey(this.state(block)) !== saved) this.schedule(block);
    }

    // A snapshot keeps the options as the reviewer left them, which a save may
    // not have confirmed. The server renders the confirmed ones as `checked`.
    savedIndexes(block) {
        const saved = block.dataset.decisionSavedIndexes;
        if (saved !== undefined) return JSON.parse(saved);
        return [...block.querySelectorAll('input[data-decision-option]')]
            .filter((input) => input.defaultChecked)
            .map((input) => Number(input.value));
    }

    // Clear drops the picks at once and keeps the note, so a save queued
    // behind it reads no stale picks. A failed Clear puts the old picks back,
    // so a repeat click must not queue a second Clear whose snapshot has none.
    clear(block) {
        const previous = this.state(block);
        if (previous.indexes.length === 0 && this.clearPending(block)) return;
        this.fillBlock(block, { indexes: [], note: previous.note });
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
        if (this.stateKey(state) !== this.sentStates.get(block))
            this.enqueue(block, false);
        else {
            this.resumeVisit();
            this.settle();
        }
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
        this.savesLanded += 1;
        if (event.detail.success && this.isStream(event.detail.fetchResponse)) {
            // A Turbo snapshot restore compares the block with these attributes.
            block.dataset.decisionNote = state.note;
            block.dataset.decisionSavedIndexes = JSON.stringify(state.indexes);
        } else {
            this.sentStates.delete(block);
            // A checked radio fires no `change` when clicked again, so the
            // confirmed picks come back to let the reviewer retry the choice.
            const edited = this.queue.has(block) || this.timers.has(block);
            if (!edited)
                this.fillBlock(
                    block,
                    state.previous ?? {
                        indexes: this.savedIndexes(block),
                        note: this.state(block).note,
                    },
                );
            if (!this.isStream(event.detail.fetchResponse)) this.showError();
            this.heldVisit = null;
        }
        this.flush();
        this.resumeVisit();
        this.settle();
    }

    resumeVisit() {
        if (!this.heldVisit || this.pending()) return;
        const url = this.heldVisit;
        this.heldVisit = null;
        window.Turbo.visit(url);
    }

    isStream(response) {
        return Boolean(response?.contentType?.startsWith(STREAM_TYPE));
    }

    // The payload only signals a change. Two saves can publish in either
    // order, so the page reads the stored answers instead.
    receive(change) {
        if (change.own) return undefined;
        const read = this.refreshSummary();
        if (change.answeredBy) this.showChangedBy(change.answeredBy);
        return read;
    }

    busy(block) {
        return (
            this.queue.has(block) ||
            this.timers.has(block) ||
            this.inFlight?.block === block
        );
    }

    // A busy block keeps its state until its own save lands, then the page
    // reads the stored answers again.
    reconcile(answers) {
        for (const block of this.element.querySelectorAll(
            'fieldset[data-decision-id]',
        )) {
            const answer = answers[block.dataset.decisionId];
            if (!answer) continue;
            if (this.busy(block)) this.deferred = true;
            else this.apply(block, answer);
        }
    }

    // A note with an unsaved edit stays. A focused note with no edit takes the
    // stored note, or its blur would save the old note over it.
    apply(block, { indexes, note }) {
        const stored = note ?? '';
        const field = block.querySelector('[data-decision-note-field]');
        const edited =
            field !== null &&
            field.value !== (block.dataset.decisionNote ?? '');
        block.dataset.decisionSavedIndexes = JSON.stringify(indexes);
        block.dataset.decisionNote = stored;
        for (const input of block.querySelectorAll(
            'input[data-decision-option]',
        ))
            input.checked = indexes.includes(Number(input.value));
        if (field && !edited && field.value !== stored) field.value = stored;
        if (field === null && stored !== '') this.decorate(block);
        this.sentStates.set(
            block,
            this.stateKey({ indexes: this.state(block).indexes, note: stored }),
        );
    }

    settle() {
        if (!this.deferred || this.pending()) return;
        this.deferred = false;
        this.refreshSummary();
    }

    // One read at a time. A change during a read asks for one more read, and
    // so does a save that lands during it, because the read may predate it.
    // The promise settles when the reads stop.
    refreshSummary() {
        if (!this.hasSummaryUrlValue) return undefined;
        if (this.summaryRunning) {
            this.summaryAgain = true;
            return this.summaryReads;
        }
        this.summaryRunning = true;
        this.summaryReads = this.readSummaries();
        return this.summaryReads;
    }

    async readSummaries() {
        do {
            this.summaryAgain = false;
            const landed = this.savesLanded;
            const html = await this.readSummary();
            if (html === null || !this.element.isConnected) continue;
            if (landed !== this.savesLanded) {
                this.summaryAgain = true;
                continue;
            }
            renderStreamMessage(html);
            const template = document.createElement('template');
            template.innerHTML = html;
            const answers = template.content.querySelector(
                'turbo-stream[data-decision-answers]',
            )?.dataset.decisionAnswers;
            if (answers) this.reconcile(JSON.parse(answers));
        } while (this.summaryAgain);
        this.summaryRunning = false;
    }

    async readSummary() {
        try {
            // A read of the summary, with no form to submit.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.summaryUrlValue, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
            });
            const type = response.headers.get('Content-Type') ?? '';
            if (response.ok && type.startsWith(STREAM_TYPE))
                return await response.text();
        } catch {
            // The next change or reconnect reads the summary again.
        }
        return null;
    }

    showChangedBy(name) {
        const status = document.getElementById('decision-status');
        if (!status) return;
        const message = document.createElement('span');
        message.className = 'lp-decision-status__message';
        message.textContent = this.changedByValue.replace('%name%', name);
        status.replaceChildren(message);
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
