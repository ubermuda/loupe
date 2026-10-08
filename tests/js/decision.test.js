/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DecisionController from '../../assets/controllers/decision_controller.js';
import { on } from '../../assets/lib/live.js';

vi.mock('@hotwired/turbo', () => ({ renderStreamMessage: vi.fn() }));
vi.mock('../../assets/lib/live.js', () => ({ on: vi.fn(() => () => {}) }));

const SUMMARY = '/projects/p/documents/doc-1/decisions/summary?versionNumber=3';
const STREAM = 'text/vnd.turbo-stream.html; charset=UTF-8';

let application;
let sent;
let receive;
let liveOptions;
let unsubscribe;
let stored;

const summaryHtml = (answers) =>
    `<turbo-stream data-decision-page="doc-1/3" action="update" target="decision-summary-count" data-decision-answers="${JSON.stringify(
        answers,
    ).replaceAll('"', '&quot;')}"><template>1/2</template></turbo-stream>`;

const summary = (answers) => ({
    ok: true,
    headers: new Headers({ 'Content-Type': STREAM }),
    text: () => Promise.resolve(summaryHtml(answers)),
});

beforeEach(() => {
    vi.useFakeTimers();
    sent = [];
    receive = undefined;
    liveOptions = undefined;
    stored = {};
    unsubscribe = vi.fn();
    on.mockReset();
    on.mockImplementation((types, handler, options) => {
        receive = handler;
        liveOptions = options;
        return unsubscribe;
    });
    renderStreamMessage.mockReset();
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve(summary(stored))),
    );
    application = Application.start();
    application.register('decision', DecisionController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    delete window.Turbo;
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

const option = (id, index, checked, kind) =>
    `<input type="${kind}" name="pick-${id}" value="${index}" data-decision-option="${id}:${index}"${
        checked.includes(`${id}:${index}`) ? ' checked' : ''
    }>`;

const block = (
    id,
    note,
    checked = [],
    kind = 'checkbox',
) => `<fieldset data-decision-id="${id}"${
    note === undefined ? '' : ` data-decision-note="${note}"`
}>
    <legend>Question ${id}</legend>
    <div>${option(id, 0, checked, kind)}<label>A</label></div>
    <div>${option(id, 1, checked, kind)}<label>B</label></div>
</fieldset>`;

const form = `<form hidden data-decision-target="form"
        data-action="turbo:before-fetch-response->decision#inspect turbo:submit-end->decision#saved">
    <input data-decision-target="decisionId" name="f[decisionId]">
    <input data-decision-target="versionNumber" name="f[versionNumber]" value="3">
    <div data-decision-target="options" data-field-name="f[optionIndexes]"></div>
    <input data-decision-target="note" name="f[note]">
    <input type="checkbox" data-decision-target="clear" name="f[clear]" value="1">
</form>`;

async function mount({
    editable = true,
    notes = {},
    page = 'doc-1/3',
    checked = [],
    kind = 'checkbox',
} = {}) {
    document.body.innerHTML = `<p id="decision-status"></p>
<div data-controller="decision" data-action="change->decision#select"
        data-decision-page="${page}"
        data-decision-summary-url-value="${SUMMARY}"
        data-decision-changed-by-value="Changed by %name%."
        data-decision-note-label-value="Note"
        data-decision-note-placeholder-value="Add a note"
        data-decision-clear-label-value="Clear choice"
        data-decision-error-message-value="Could not save.">
    <div class="prose">${block('a', notes.a, checked, kind)}${block('b', notes.b, checked, kind)}</div>
    ${editable ? form : ''}
</div>`;
    const formElement = document.querySelector('form');
    if (formElement) {
        formElement.requestSubmit = () => sent.push(snapshot(formElement));
    }
    await vi.advanceTimersByTimeAsync(0);
}

function snapshot(formElement) {
    return {
        decisionId: formElement.querySelector(
            '[data-decision-target="decisionId"]',
        ).value,
        indexes: [
            ...formElement.querySelectorAll(
                '[data-decision-target="options"] input',
            ),
        ].map((input) => input.value),
        note: formElement.querySelector('[data-decision-target="note"]').value,
        clear: formElement.querySelector('[data-decision-target="clear"]')
            .checked,
    };
}

const streamed = {
    succeeded: true,
    contentType: 'text/vnd.turbo-stream.html; charset=UTF-8',
};

function finish(detail = { success: true, fetchResponse: streamed }) {
    document
        .querySelector('form')
        .dispatchEvent(
            new CustomEvent('turbo:submit-end', { bubbles: true, detail }),
        );
}

function check(id, index) {
    const input = document.querySelector(
        `[data-decision-option="${id}:${index}"]`,
    );
    input.checked = !input.checked;
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

const note = (id) =>
    document.querySelector(`[data-decision-id="${id}"] textarea`);

function type(id, value) {
    note(id).value = value;
    note(id).dispatchEvent(new Event('input', { bubbles: true }));
}

it('adds no text node to the blocks', async () => {
    const before = document.createElement('div');
    before.innerHTML = block('a', 'Kept');
    await mount({ notes: { a: 'Kept' } });

    expect(
        document.querySelector('.prose [data-decision-id="a"]').textContent,
    ).toBe(before.firstElementChild.textContent);
    expect(note('a').value).toBe('Kept');
    expect(note('a').getAttribute('aria-label')).toBe('Note');
    expect(note('a').getAttribute('placeholder')).toBe('Add a note');
    expect(
        document.querySelector('[data-decision-id="a"] input[type="button"]')
            .value,
    ).toBe('Clear choice');
});

it('puts Clear choice in the block header, away from the note field', async () => {
    await mount({ notes: { a: 'Kept' } });
    const fieldset = document.querySelector('fieldset[data-decision-id="a"]');
    const button = fieldset.querySelector('input[type="button"]');

    expect(button.parentElement).toBe(fieldset);
    expect(fieldset.firstElementChild.tagName).toBe('LEGEND');
    expect(fieldset.firstElementChild.nextElementSibling).toBe(button);
    expect(fieldset.querySelector('.lp-decision__note').contains(button)).toBe(
        false,
    );
    expect(button.textContent).toBe('');
});

it('keeps one Clear choice button when a block is decorated again', async () => {
    await mount({ notes: { a: 'Kept' } });
    const controller = application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="decision"]'),
        'decision',
    );
    controller.decorate(
        document.querySelector('fieldset[data-decision-id="a"]'),
    );

    expect(
        document.querySelectorAll(
            'fieldset[data-decision-id="a"] input[type="button"]',
        ),
    ).toHaveLength(1);
});

it('sends the whole block at once when an option changes', async () => {
    await mount();
    type('a', 'Why');
    check('a', 1);

    expect(sent).toEqual([
        { decisionId: 'a', indexes: ['1'], note: 'Why', clear: false },
    ]);
});

it('sends one save at a time and only the newest state of a block', async () => {
    await mount();
    check('a', 0);
    check('a', 1);
    check('b', 0);
    check('a', 0);

    expect(sent).toHaveLength(1);
    finish();
    expect(sent[1]).toEqual({
        decisionId: 'a',
        indexes: ['1'],
        note: '',
        clear: false,
    });
    finish();
    expect(sent[2]).toEqual({
        decisionId: 'b',
        indexes: ['0'],
        note: '',
        clear: false,
    });
    finish();
    expect(sent).toHaveLength(3);
});

it('does not disable the inputs while a save runs', async () => {
    await mount();
    check('a', 0);

    expect(
        document.querySelector('[data-decision-option="a:1"]').disabled,
    ).toBe(false);
    expect(note('a').disabled).toBe(false);
});

it('saves the note once typing stops', async () => {
    await mount();
    type('a', 'Fi');
    await vi.advanceTimersByTimeAsync(500);
    type('a', 'First');
    await vi.advanceTimersByTimeAsync(799);
    expect(sent).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(1);
    expect(sent).toEqual([
        { decisionId: 'a', indexes: [], note: 'First', clear: false },
    ]);
});

it('saves the note on blur only when it changed', async () => {
    await mount({ notes: { a: 'Kept' } });
    note('a').dispatchEvent(new Event('blur'));
    expect(sent).toHaveLength(0);

    type('a', 'Changed');
    note('a').dispatchEvent(new Event('blur'));
    expect(sent).toEqual([
        { decisionId: 'a', indexes: [], note: 'Changed', clear: false },
    ]);
    finish();

    note('a').dispatchEvent(new Event('blur'));
    await vi.advanceTimersByTimeAsync(1000);
    expect(sent).toHaveLength(1);
});

it('does not save a note change through the change event', async () => {
    await mount();
    type('a', 'Typed');
    note('a').dispatchEvent(new Event('change', { bubbles: true }));

    expect(sent).toHaveLength(0);
});

it('clears the picks at once, keeps the note and saves the Clear', async () => {
    await mount({ notes: { a: 'Kept' } });
    check('a', 0);
    finish();
    document
        .querySelector('[data-decision-id="a"] input[type="button"]')
        .click();

    expect(sent[1]).toEqual({
        decisionId: 'a',
        indexes: [],
        note: 'Kept',
        clear: true,
    });
    expect(note('a').value).toBe('Kept');
    expect(document.querySelector('[data-decision-option="a:0"]').checked).toBe(
        false,
    );
    finish();
    expect(note('a').value).toBe('Kept');
    expect(document.querySelector('[data-decision-option="a:0"]').checked).toBe(
        false,
    );
});

it('keeps the note and reports a network failure', async () => {
    await mount();
    type('a', 'Unsaved words');
    note('a').dispatchEvent(new Event('blur'));
    finish({ success: false, error: new TypeError('Failed to fetch') });

    expect(note('a').value).toBe('Unsaved words');
    const status = document.getElementById('decision-status');
    expect(status.textContent).toBe('Could not save.');
    expect(
        status.querySelector('.lp-decision-status__message--failed'),
    ).not.toBeNull();

    note('a').dispatchEvent(new Event('blur'));
    expect(sent).toHaveLength(2);
});

it('leaves a refusal the server streamed on the status line', async () => {
    await mount();
    check('a', 0);
    document.getElementById('decision-status').textContent = 'Streamed reason.';
    finish({
        success: false,
        fetchResponse: {
            contentType: 'text/vnd.turbo-stream.html; charset=UTF-8',
        },
    });

    expect(document.getElementById('decision-status').textContent).toBe(
        'Streamed reason.',
    );
});

it('stops Turbo from rendering a failed page response', async () => {
    await mount();
    const event = new CustomEvent('turbo:before-fetch-response', {
        bubbles: true,
        cancelable: true,
        detail: {
            fetchResponse: { succeeded: false, contentType: 'text/html' },
        },
    });
    document.querySelector('form').dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
});

it('stops Turbo from rendering a page response that succeeded', async () => {
    await mount();
    const event = new CustomEvent('turbo:before-fetch-response', {
        bubbles: true,
        cancelable: true,
        detail: {
            fetchResponse: { succeeded: true, contentType: 'text/html' },
        },
    });
    document.querySelector('form').dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
});

it('lets Turbo render a streamed response', async () => {
    await mount();
    const event = new CustomEvent('turbo:before-fetch-response', {
        bubbles: true,
        cancelable: true,
        detail: { fetchResponse: streamed },
    });
    document.querySelector('form').dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
});

it('reports a page response that succeeded as a failed save', async () => {
    await mount({ notes: { a: 'Old' } });
    type('a', 'Unsaved words');
    note('a').dispatchEvent(new Event('blur'));
    finish({
        success: true,
        fetchResponse: { succeeded: true, contentType: 'text/html' },
    });

    expect(document.getElementById('decision-status').textContent).toBe(
        'Could not save.',
    );
    expect(
        document.querySelector('[data-decision-id="a"]').dataset.decisionNote,
    ).toBe('Old');
    note('a').dispatchEvent(new Event('blur'));
    expect(sent).toHaveLength(2);
});

it('sends a note still waiting for its timer before a Turbo visit', async () => {
    await mount();
    type('a', 'Typed before leaving');
    visit();

    expect(sent).toEqual([
        {
            decisionId: 'a',
            indexes: [],
            note: 'Typed before leaving',
            clear: false,
        },
    ]);
});

it('stops listening for Turbo visits once disconnected', async () => {
    await mount();
    type('a', 'Typed');
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    visit();

    expect(sent).toEqual([]);
    expect(unload().defaultPrevented).toBe(false);
});

function visit(url = '/next') {
    const event = new CustomEvent('turbo:before-visit', {
        cancelable: true,
        detail: { url },
    });
    document.dispatchEvent(event);
    return event;
}

function unload() {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    return event;
}

it('holds a visit until a save queued behind the one in flight is sent', async () => {
    window.Turbo = { visit: vi.fn() };
    await mount();
    check('a', 0);
    check('a', 1);

    expect(visit('/next').defaultPrevented).toBe(true);
    expect(sent).toHaveLength(1);
    finish();
    expect(sent[1]).toEqual({
        decisionId: 'a',
        indexes: ['0', '1'],
        note: '',
        clear: false,
    });
    expect(window.Turbo.visit).not.toHaveBeenCalled();
    finish();
    expect(window.Turbo.visit).toHaveBeenCalledOnce();
    expect(window.Turbo.visit).toHaveBeenCalledWith('/next');
    expect(visit('/next').defaultPrevented).toBe(false);
});

it('lets a visit go when no save is pending', async () => {
    await mount();
    check('a', 0);
    finish();

    expect(visit().defaultPrevented).toBe(false);
});

it('stays on the page and reports the error when the held save fails', async () => {
    window.Turbo = { visit: vi.fn() };
    await mount();
    check('a', 0);
    visit();
    finish({ success: false, error: new TypeError('Failed to fetch') });

    expect(window.Turbo.visit).not.toHaveBeenCalled();
    expect(document.getElementById('decision-status').textContent).toBe(
        'Could not save.',
    );
});

it('asks before an unload only while a save is pending', async () => {
    await mount();
    expect(unload().defaultPrevented).toBe(false);

    check('a', 0);
    expect(unload().defaultPrevented).toBe(true);
    finish();
    expect(unload().defaultPrevented).toBe(false);

    type('a', 'Waiting');
    expect(unload().defaultPrevented).toBe(true);
});

it('shows a read-only note with no Clear and saves nothing', async () => {
    await mount({ editable: false, notes: { a: 'Old answer' } });

    expect(note('a').value).toBe('Old answer');
    expect(note('a').readOnly).toBe(true);
    expect(note('b')).toBeNull();
    expect(document.querySelector('input[type="button"]')).toBeNull();
});

it('builds its controls once when it reconnects', async () => {
    await mount();
    const element = document.querySelector('[data-controller="decision"]');
    element.removeAttribute('data-controller');
    await vi.advanceTimersByTimeAsync(0);
    element.setAttribute('data-controller', 'decision');
    await vi.advanceTimersByTimeAsync(0);

    expect(
        document.querySelectorAll('[data-decision-id="a"] textarea'),
    ).toHaveLength(1);
});

async function restoreSnapshot() {
    const element = document.querySelector('[data-controller="decision"]');
    const copy = element.cloneNode(true);
    element.replaceWith(copy);
    const copiedForm = copy.querySelector('form');
    copiedForm.requestSubmit = () => sent.push(snapshot(copiedForm));
    await vi.advanceTimersByTimeAsync(0);
}

it('shows the saved note after Turbo restores a snapshot', async () => {
    await mount({ notes: { a: 'Old' } });
    type('a', 'New');
    note('a').dispatchEvent(new Event('blur'));
    finish();
    await restoreSnapshot();

    expect(
        document.querySelectorAll('[data-decision-id="a"] textarea'),
    ).toHaveLength(1);
    expect(
        document.querySelectorAll(
            '[data-decision-id="a"] input[type="button"]',
        ),
    ).toHaveLength(1);
    expect(note('a').value).toBe('New');
});

it('keeps the note after Turbo restores a snapshot taken after a Clear', async () => {
    await mount({ notes: { a: 'Kept' }, checked: ['a:0'] });
    document
        .querySelector('[data-decision-id="a"] input[type="button"]')
        .click();
    finish();
    await restoreSnapshot();
    await vi.advanceTimersByTimeAsync(1000);

    expect(note('a').value).toBe('Kept');
    expect(document.querySelector('[data-decision-option="a:0"]').checked).toBe(
        false,
    );
    expect(sent).toHaveLength(1);
});

it('keeps and saves a note typed while a Clear is in flight', async () => {
    await mount({ notes: { a: 'Kept' } });
    check('a', 0);
    finish();
    document
        .querySelector('[data-decision-id="a"] input[type="button"]')
        .click();
    type('a', 'Typed after');
    finish();

    expect(note('a').value).toBe('Typed after');
    expect(document.querySelector('[data-decision-option="a:0"]').checked).toBe(
        false,
    );
    await vi.advanceTimersByTimeAsync(800);
    expect(sent[2]).toEqual({
        decisionId: 'a',
        indexes: [],
        note: 'Typed after',
        clear: false,
    });
});

const clear = (id) =>
    document
        .querySelector(`[data-decision-id="${id}"] input[type="button"]`)
        .click();

it('does not restore cleared options with a note saved while Clear is in flight', async () => {
    await mount({ notes: { a: 'Kept' } });
    check('a', 0);
    finish();
    clear('a');
    type('a', 'Typed after');
    note('a').dispatchEvent(new Event('blur'));
    finish();

    expect(sent[2]).toEqual({
        decisionId: 'a',
        indexes: [],
        note: 'Typed after',
        clear: false,
    });
});

it('saves an option checked while Clear is in flight', async () => {
    await mount();
    check('a', 0);
    finish();
    clear('a');
    check('a', 1);
    finish();

    expect(sent[2]).toEqual({
        decisionId: 'a',
        indexes: ['1'],
        note: '',
        clear: false,
    });
});

it('restores the block when a Clear fails', async () => {
    await mount({ notes: { a: 'Kept' } });
    check('a', 0);
    finish();
    clear('a');
    finish({ success: false, error: new TypeError('Failed to fetch') });

    expect(note('a').value).toBe('Kept');
    expect(document.querySelector('[data-decision-option="a:0"]').checked).toBe(
        true,
    );
    expect(document.getElementById('decision-status').textContent).toBe(
        'Could not save.',
    );
});

it('restores the first answer when a second Clear click fails', async () => {
    await mount({ notes: { a: 'Kept' } });
    check('a', 0);
    finish();
    clear('a');
    clear('a');
    finish({ success: false, error: new TypeError('Failed to fetch') });
    finish({ success: false, error: new TypeError('Failed to fetch') });

    expect(sent).toHaveLength(2);
    expect(note('a').value).toBe('Kept');
    expect(document.querySelector('[data-decision-option="a:0"]').checked).toBe(
        true,
    );
});

const radio = (id, index) =>
    document.querySelector(`[data-decision-option="${id}:${index}"]`);

it('puts the confirmed pick back when an option save fails, and keeps the note', async () => {
    await mount({ kind: 'radio', checked: ['a:0'], notes: { a: 'Kept' } });
    type('a', 'Typed');
    radio('a', 1).click();
    finish({ success: false, error: new TypeError('Failed to fetch') });

    expect(radio('a', 0).checked).toBe(true);
    expect(radio('a', 1).checked).toBe(false);
    expect(note('a').value).toBe('Typed');
    expect(document.getElementById('decision-status').textContent).toBe(
        'Could not save.',
    );
});

it('retries a failed option save when the same option is clicked again', async () => {
    await mount({ kind: 'radio', checked: ['a:0'] });
    radio('a', 1).click();
    finish({ success: false, error: new TypeError('Failed to fetch') });
    radio('a', 1).click();

    expect(sent).toHaveLength(2);
    expect(sent[1]).toEqual({
        decisionId: 'a',
        indexes: ['1'],
        note: '',
        clear: false,
    });
});

it('keeps an option picked while the failing save was in flight', async () => {
    await mount({ kind: 'radio' });
    radio('a', 1).click();
    radio('a', 0).click();
    finish({ success: false, error: new TypeError('Failed to fetch') });

    expect(radio('a', 0).checked).toBe(true);
    expect(sent[1]).toEqual({
        decisionId: 'a',
        indexes: ['0'],
        note: '',
        clear: false,
    });
});

function streamRender(page) {
    const stream = document.createElement('turbo-stream');
    if (page !== undefined) stream.dataset.decisionPage = page;
    document.documentElement.append(stream);
    const event = new CustomEvent('turbo:before-stream-render', {
        bubbles: true,
        cancelable: true,
    });
    stream.dispatchEvent(event);
    stream.remove();
    return event;
}

it('renders a decision stream on the page that sent the save', async () => {
    await mount();

    expect(streamRender('doc-1/3').defaultPrevented).toBe(false);
});

it('drops a decision stream that arrives on another document or version', async () => {
    await mount();

    expect(streamRender('doc-2/3').defaultPrevented).toBe(true);
    expect(streamRender('doc-1/2').defaultPrevented).toBe(true);
});

it('drops a decision stream that arrives on a page with no decisions', async () => {
    document.body.innerHTML = '<p id="decision-status"></p>';

    expect(streamRender('doc-1/3').defaultPrevented).toBe(true);
});

it('lets every other stream render', async () => {
    document.body.innerHTML = '<p>Another page</p>';

    expect(streamRender().defaultPrevented).toBe(false);
});

it('restores and saves a note typed after the last confirmed save', async () => {
    await mount({ notes: { a: 'Old' } });
    type('a', 'New');
    note('a').dispatchEvent(new Event('blur'));
    document.dispatchEvent(new Event('turbo:before-cache'));
    await restoreSnapshot();

    const restored = document.querySelector('[data-decision-id="a"]');
    expect(restored.querySelectorAll('textarea')).toHaveLength(1);
    expect(restored.querySelectorAll('input[type="button"]')).toHaveLength(1);
    expect(note('a').value).toBe('New');
    expect(restored.hasAttribute('data-decision-note-draft')).toBe(false);

    check('a', 0);
    expect(sent[1]).toEqual({
        decisionId: 'a',
        indexes: ['0'],
        note: 'New',
        clear: false,
    });
});

it('saves a restored note that no save confirmed', async () => {
    await mount({ notes: { a: 'Old' } });
    type('a', 'New');
    document.dispatchEvent(new Event('turbo:before-cache'));
    await restoreSnapshot();
    await vi.advanceTimersByTimeAsync(800);

    expect(sent.at(-1)).toEqual({
        decisionId: 'a',
        indexes: [],
        note: 'New',
        clear: false,
    });
});

it('does not save again after restoring a note that is already saved', async () => {
    await mount({ notes: { a: 'Old' } });
    type('a', 'New');
    note('a').dispatchEvent(new Event('blur'));
    finish();
    document.dispatchEvent(new Event('turbo:before-cache'));
    await restoreSnapshot();
    await vi.advanceTimersByTimeAsync(1000);

    expect(sent).toHaveLength(1);
    expect(note('a').value).toBe('New');
});

it('resumes a held visit when a note edit ends where it started', async () => {
    window.Turbo = { visit: vi.fn() };
    await mount();
    check('a', 0);
    visit('/next');
    type('b', 'Draft');
    type('b', '');
    finish();
    await vi.advanceTimersByTimeAsync(800);

    expect(sent).toHaveLength(1);
    expect(window.Turbo.visit).toHaveBeenCalledWith('/next');
});

it('saves an option that no save confirmed after Turbo restores a snapshot', async () => {
    await mount();
    check('a', 0);
    check('b', 1);
    document.dispatchEvent(new Event('turbo:before-cache'));
    await restoreSnapshot();
    await vi.advanceTimersByTimeAsync(800);
    finish();

    expect(sent.at(-1)).toEqual({
        decisionId: 'b',
        indexes: ['1'],
        note: '',
        clear: false,
    });
});

it('saves nothing when a page loads with its saved options checked', async () => {
    await mount({ checked: ['a:1'], notes: { a: 'Kept' } });
    check('b', 0);
    finish();
    document.dispatchEvent(new Event('turbo:before-cache'));
    await restoreSnapshot();
    await vi.advanceTimersByTimeAsync(1000);

    expect(sent).toHaveLength(1);
});

it('sends the Clear again when Turbo cached the page while it was in flight', async () => {
    await mount({ notes: { a: 'Kept' } });
    check('a', 0);
    finish();
    clear('a');
    document.dispatchEvent(new Event('turbo:before-cache'));
    await restoreSnapshot();
    await vi.advanceTimersByTimeAsync(800);

    expect(note('a').value).toBe('Kept');
    expect(sent.at(-1)).toEqual({
        decisionId: 'a',
        indexes: [],
        note: 'Kept',
        clear: false,
    });
});

const change = (fields = {}) => ({
    type: 'review.decision_changed',
    decisionId: 'a',
    versionNumber: 3,
    optionIndexes: [1],
    note: 'Theirs',
    answeredBy: 'Ann Other',
    answeredAt: '2026-09-29T10:00:00+00:00',
    local: false,
    own: false,
    ...fields,
});

const checked = (id) =>
    [
        ...document.querySelectorAll(
            `[data-decision-id="${id}"] input[data-decision-option]:checked`,
        ),
    ].map((input) => input.value);

it('ignores a change that this tab saved', async () => {
    await mount();
    receive(change({ own: true }));
    await vi.advanceTimersByTimeAsync(0);

    expect(checked('a')).toEqual([]);
    expect(fetch).not.toHaveBeenCalled();
});

it('shows the stored picks and note after a change, and does not send them back', async () => {
    await mount({ notes: { a: 'Mine' } });
    stored = { a: { indexes: [1], note: 'Theirs' } };
    receive(change());
    await vi.advanceTimersByTimeAsync(0);

    expect(checked('a')).toEqual(['1']);
    expect(note('a').value).toBe('Theirs');
    const fieldset = document.querySelector('[data-decision-id="a"]');
    expect(fieldset.dataset.decisionSavedIndexes).toBe('[1]');
    expect(fieldset.dataset.decisionNote).toBe('Theirs');
    expect(document.getElementById('decision-status').textContent).toBe(
        'Changed by Ann Other.',
    );

    note('a').dispatchEvent(new Event('blur'));
    expect(sent).toHaveLength(0);
});

it('ends at the stored answer when two changes arrive in reverse order', async () => {
    await mount();
    stored = { a: { indexes: [1], note: 'Newer' } };
    receive(change({ optionIndexes: [1], note: 'Newer' }));
    receive(change({ optionIndexes: [0], note: 'Older' }));
    await vi.advanceTimersByTimeAsync(0);

    expect(checked('a')).toEqual(['1']);
    expect(note('a').value).toBe('Newer');
});

it('leaves a block alone while its own save is in flight or waiting', async () => {
    await mount();
    check('a', 0);
    type('b', 'Mine');
    stored = {
        a: { indexes: [1], note: null },
        b: { indexes: [1], note: null },
    };
    receive(change());
    await vi.advanceTimersByTimeAsync(0);

    expect(checked('a')).toEqual(['0']);
    expect(checked('b')).toEqual([]);
    expect(note('b').value).toBe('Mine');
});

it('reads the stored answers again once its own saves land', async () => {
    await mount();
    check('a', 0);
    stored = { a: { indexes: [1], note: null } };
    receive(change());
    await vi.advanceTimersByTimeAsync(0);
    expect(checked('a')).toEqual(['0']);
    expect(fetch).toHaveBeenCalledOnce();

    finish();
    await vi.advanceTimersByTimeAsync(0);
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(checked('a')).toEqual(['1']);
});

it('drops a read that a save of its own overtook, and reads again', async () => {
    let answer;
    fetch.mockImplementationOnce(
        () => new Promise((resolve) => (answer = resolve)),
    );
    await mount();
    receive(change());
    check('a', 0);
    finish();
    answer(summary({ a: { indexes: [], note: null } }));
    stored = { a: { indexes: [0], note: null } };
    await vi.advanceTimersByTimeAsync(0);

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(renderStreamMessage).toHaveBeenCalledOnce();
    expect(checked('a')).toEqual(['0']);
});

it('reads the stored answers each time the hub connection opens', async () => {
    await mount();
    stored = { a: { indexes: [1], note: null } };
    liveOptions.onOpen();
    await vi.advanceTimersByTimeAsync(0);

    expect(fetch).toHaveBeenCalledOnce();
    expect(checked('a')).toEqual(['1']);
});

it('keeps a note with an unsaved edit, and saves it with the new picks', async () => {
    await mount({ notes: { a: 'Old' } });
    note('a').focus();
    note('a').value = 'Draft';
    stored = { a: { indexes: [0], note: 'Theirs' } };
    receive(change());
    await vi.advanceTimersByTimeAsync(0);

    expect(note('a').value).toBe('Draft');
    expect(checked('a')).toEqual(['0']);
    note('a').dispatchEvent(new Event('blur'));
    expect(sent).toEqual([
        { decisionId: 'a', indexes: ['0'], note: 'Draft', clear: false },
    ]);
});

it('gives a focused note with no edit the stored note', async () => {
    await mount({ notes: { a: 'Old' } });
    note('a').focus();
    stored = { a: { indexes: [], note: 'Theirs' } };
    receive(change());
    await vi.advanceTimersByTimeAsync(0);

    expect(note('a').value).toBe('Theirs');
    note('a').dispatchEvent(new Event('blur'));
    expect(sent).toHaveLength(0);
});

it('shows the stored answer on a read-only page', async () => {
    await mount({ editable: false });
    stored = { b: { indexes: [0], note: 'Theirs' } };
    receive(change({ decisionId: 'b' }));
    await vi.advanceTimersByTimeAsync(0);

    expect(checked('b')).toEqual(['0']);
    expect(note('b').value).toBe('Theirs');
    expect(note('b').readOnly).toBe(true);
});

it('renders the summary it reads as a stream', async () => {
    await mount();
    receive(change());
    await vi.advanceTimersByTimeAsync(0);

    expect(fetch).toHaveBeenCalledWith(SUMMARY, {
        headers: { Accept: 'text/vnd.turbo-stream.html' },
        credentials: 'same-origin',
    });
    expect(renderStreamMessage).toHaveBeenCalledWith(summaryHtml({}));
});

it('reads the summary once more for a burst that arrives during a read', async () => {
    let answer;
    fetch.mockImplementationOnce(
        () => new Promise((resolve) => (answer = resolve)),
    );
    await mount();
    receive(change());
    receive(change());
    receive(change());
    expect(fetch).toHaveBeenCalledOnce();

    answer({ ok: false, headers: new Headers() });
    await vi.advanceTimersByTimeAsync(0);
    expect(fetch).toHaveBeenCalledTimes(2);
});

it('ignores a summary read that fails', async () => {
    fetch.mockRejectedValueOnce(new TypeError('offline'));
    await mount();
    receive(change());
    await vi.advanceTimersByTimeAsync(0);

    expect(renderStreamMessage).not.toHaveBeenCalled();
    receive(change());
    await vi.advanceTimersByTimeAsync(0);
    expect(renderStreamMessage).toHaveBeenCalledOnce();
});

it('writes no name when the change has none', async () => {
    await mount();
    receive(change({ answeredBy: null }));
    await vi.advanceTimersByTimeAsync(0);

    expect(document.getElementById('decision-status').textContent).toBe('');
});

it('marks the page while the hub connection is open', async () => {
    let options;
    on.mockImplementation((types, handler, given) => {
        options = given;
        return unsubscribe;
    });
    await mount();
    const page = document.querySelector('[data-controller="decision"]');
    expect(page.hasAttribute('data-decision-connected')).toBe(false);

    options.onOpen();
    expect(page.hasAttribute('data-decision-connected')).toBe(true);
    options.onError();
    expect(page.hasAttribute('data-decision-connected')).toBe(false);
});

it('listens only on a page that is not a comparison, and stops on disconnect', async () => {
    document.body.innerHTML = '<div data-controller="decision"></div>';
    await vi.advanceTimersByTimeAsync(0);
    expect(on).not.toHaveBeenCalled();

    await mount();
    expect(on).toHaveBeenCalledWith(
        'review.decision_changed',
        expect.any(Function),
        expect.any(Object),
    );
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(unsubscribe).toHaveBeenCalled();
});

function tracked(promise) {
    const state = { settled: false };
    promise.then(() => (state.settled = true));

    return state;
}

it('answers no promise for a change this tab saved', async () => {
    await mount();
    expect(receive(change({ own: true }))).toBeUndefined();
});

it('settles the promise of a change once the summary it reads renders', async () => {
    let answer;
    fetch.mockImplementationOnce(
        () => new Promise((resolve) => (answer = resolve)),
    );
    await mount();
    const result = tracked(receive(change()));
    await vi.advanceTimersByTimeAsync(0);
    expect(result.settled).toBe(false);

    answer(summary({}));
    await vi.advanceTimersByTimeAsync(0);
    expect(renderStreamMessage).toHaveBeenCalledOnce();
    expect(result.settled).toBe(true);
});

it('keeps a change during a read waiting for the read after it', async () => {
    const answers = [];
    fetch.mockImplementation(
        () => new Promise((resolve) => answers.push(resolve)),
    );
    await mount();
    const first = tracked(receive(change()));
    const second = tracked(receive(change()));
    answers[0](summary({}));
    await vi.advanceTimersByTimeAsync(0);
    expect(renderStreamMessage).toHaveBeenCalledOnce();
    expect(first.settled).toBe(false);
    expect(second.settled).toBe(false);

    answers[1](summary({}));
    await vi.advanceTimersByTimeAsync(0);
    expect(first.settled).toBe(true);
    expect(second.settled).toBe(true);
});

it('settles the promise of a read that answers after it disconnects', async () => {
    let answer;
    fetch.mockImplementationOnce(
        () => new Promise((resolve) => (answer = resolve)),
    );
    await mount();
    const result = tracked(receive(change()));
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(result.settled).toBe(false);

    answer(summary({}));
    await vi.advanceTimersByTimeAsync(0);
    expect(renderStreamMessage).not.toHaveBeenCalled();
    expect(result.settled).toBe(true);
});
