/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import FormDraftController from '../../assets/controllers/form_draft_controller.js';
import {
    discardFormDraft,
    formDraft,
    useFormDraftOwner,
} from '../../assets/lib/form_drafts.js';

let application;
let owner;

async function mount(markup = '') {
    if (markup instanceof Element) document.body.replaceChildren(markup);
    else
        document.body.innerHTML =
            markup ||
            `<form data-controller="form-draft" data-action="form-draft:cleared@document->form-draft#cleared" data-form-draft-owner-value="${owner}" data-form-draft-key-value="question">
        <input type="checkbox" value="0" data-form-draft-target="option">
        <input type="checkbox" value="1" data-form-draft-target="option">
        <input type="hidden" data-form-draft-target="selectedOptions">
        <textarea data-form-draft-target="text"></textarea>
    </form>`;
    await new Promise((resolve) => setTimeout(resolve, 0));
    return application.getControllerForElementAndIdentifier(
        document.querySelector('form'),
        'form-draft',
    );
}

beforeEach(() => {
    owner = crypto.randomUUID();
    application = Application.start();
    application.register('form-draft', FormDraftController);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    useFormDraftOwner('');
});

it('restores text and selected options after the form is replaced', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Keep this answer';
    controller.optionTargets[1].checked = true;
    controller.select();
    controller = await mount();
    expect(controller.textTarget.value).toBe('Keep this answer');
    expect(controller.optionTargets.map((option) => option.checked)).toEqual([
        false,
        true,
    ]);
    expect(controller.selectedOptionsTarget.value).toBe('1');
});

it('restores named document fields without restoring a CSRF token', async () => {
    const markup = (
        token,
    ) => `<form data-controller="form-draft" data-action="form-draft:cleared@document->form-draft#cleared" data-form-draft-owner-value="${owner}" data-form-draft-key-value="document">
        <input name="title" value="Original title" data-form-draft-target="field">
        <input name="description" value="" data-form-draft-target="field">
        <textarea data-form-draft-target="text">Original text</textarea>
        <input type="hidden" name="_token" value="${token}">
        <p data-form-draft-target="notice" hidden></p>
    </form>`;
    let controller = await mount(markup('old-token'));
    controller.fieldTargets[0].value = 'Revised title';
    controller.fieldTargets[1].value = 'Explain the revision';
    controller.textTarget.value = 'Revised text';
    controller.remember();
    expect(controller.noticeTarget.hidden).toBe(false);
    controller = await mount(markup('fresh-token'));
    expect(controller.fieldTargets.map((field) => field.value)).toEqual([
        'Revised title',
        'Explain the revision',
    ]);
    expect(controller.textTarget.value).toBe('Revised text');
    expect(controller.element.querySelector('[name="_token"]').value).toBe(
        'fresh-token',
    );
    const cached = controller.element.cloneNode(true);
    controller.start();
    controller.response({ detail: { fetchResponse: { succeeded: true } } });
    controller = await mount(cached);
    expect(controller.fieldTargets.map((field) => field.value)).toEqual([
        'Original title',
        '',
    ]);
    expect(controller.textTarget.value).toBe('Original text');
    expect(controller.noticeTarget.hidden).toBe(true);
});

it('keeps a newer draft when an earlier answer succeeds', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Submitted answer';
    controller.remember();
    controller.start();
    controller.textTarget.value = 'A newer answer';
    controller.remember();
    controller.response({ detail: { fetchResponse: { succeeded: true } } });
    controller = await mount();
    expect(controller.textTarget.value).toBe('A newer answer');
});

it('clears accepted drafts from fresh and cached forms', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Submitted answer';
    controller.remember();
    const cached = document.querySelector('form').cloneNode(true);
    expect(cached.querySelector('textarea').value).toBe('Submitted answer');
    controller.start();
    controller.response({ detail: { fetchResponse: { succeeded: true } } });
    expect(formDraft('question')).toBeUndefined();
    controller = await mount();
    expect(controller.textTarget.value).toBe('');
    controller = await mount(cached);
    expect(controller.textTarget.value).toBe('');
});

it('retains rejected answers and separates accounts', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Rejected answer';
    controller.remember();
    controller.start();
    controller.response({ detail: { fetchResponse: { succeeded: false } } });
    controller = await mount();
    expect(controller.textTarget.value).toBe('Rejected answer');
    owner = crypto.randomUUID();
    controller = await mount();
    expect(controller.textTarget.value).toBe('');
});

it('removes a draft when the fields return to their initial values', async () => {
    const controller = await mount();
    controller.textTarget.value = 'Changed';
    controller.remember();
    controller.textTarget.value = '';
    controller.remember();
    expect(formDraft('question')).toBeUndefined();
});

it('preserves edits made after reopening a pending submission', async () => {
    const original = await mount();
    original.textTarget.value = 'Submitted answer';
    original.remember();
    original.start();
    const reopened = await mount();
    reopened.textTarget.value = 'Edited after reopening';
    reopened.remember();
    document.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: {
                formSubmission: { formElement: original.element },
                success: true,
            },
        }),
    );
    const restored = await mount();
    expect(restored.textTarget.value).toBe('Edited after reopening');
});

it('acknowledges a submission after its original form disconnects', async () => {
    const original = await mount();
    original.textTarget.value = 'Submitted answer';
    original.remember();
    original.start();
    const reopened = await mount();
    document.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: {
                formSubmission: { formElement: original.element },
                success: true,
            },
        }),
    );
    expect(reopened.textTarget.value).toBe('');
    expect(formDraft('question')).toBeUndefined();
});

it('ignores unrelated successful forms before an owner is known', () => {
    useFormDraftOwner(undefined);
    document.dispatchEvent(
        new CustomEvent('turbo:submit-end', {
            detail: {
                formSubmission: { formElement: document.createElement('form') },
                success: true,
            },
        }),
    );
    expect(formDraft('question')).toBeUndefined();
});

it('does not restore a discarded draft from a cached form', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Discard this text';
    controller.remember();
    const cached = controller.element.cloneNode(true);
    discardFormDraft('question');
    controller = await mount(cached);
    expect(controller.textTarget.value).toBe('');
    expect(formDraft('question')).toBeUndefined();
});

it('restores and reveals a decline note without replacing the question draft', async () => {
    let controller = await mount();
    controller.textTarget.value = 'Question answer';
    controller.remember();
    const decline = `<details><summary>Decline</summary><form data-controller="form-draft" data-form-draft-owner-value="${owner}" data-form-draft-key-value="question:decline"><textarea data-form-draft-target="text"></textarea></form></details>`;
    controller = await mount(decline);
    controller.textTarget.value = 'Decline note';
    controller.remember();
    controller = await mount(decline);
    expect(controller.textTarget.value).toBe('Decline note');
    expect(controller.element.closest('details').open).toBe(true);
    expect(controller.hasSelectedOptionsTarget).toBe(false);
    controller = await mount();
    expect(controller.textTarget.value).toBe('Question answer');
});

it('restores review guards with the draft and resets them only on discard', async () => {
    const review = (
        version,
    ) => `<form data-controller="form-draft" data-action="form-draft:cleared@document->form-draft#cleared" data-form-draft-owner-value="${owner}" data-form-draft-key-value="question:review">
        <input type="hidden" name="version" value="${version}" data-form-draft-target="guard">
        <input type="radio" value="approve" data-form-draft-target="option">
        <textarea data-form-draft-target="text"></textarea>
        <p hidden data-form-draft-target="stale"></p>
    </form>`;
    let controller = await mount(review('1'));
    controller.textTarget.value = 'Review of version one';
    controller.optionTargets[0].checked = true;
    controller.select();
    controller = await mount(review('2'));
    expect(controller.textTarget.value).toBe('Review of version one');
    expect(controller.optionTargets[0].checked).toBe(true);
    expect(controller.element.querySelector('[name="version"]').value).toBe(
        '1',
    );
    expect(controller.element.querySelector('p').hidden).toBe(false);
    const cached = controller.element.cloneNode(true);
    controller = await mount(cached);
    expect(controller.element.querySelector('p').hidden).toBe(false);
    controller.discard();
    expect(controller.textTarget.value).toBe('');
    expect(controller.optionTargets[0].checked).toBe(false);
    expect(controller.element.querySelector('[name="version"]').value).toBe(
        '2',
    );
    expect(controller.element.querySelector('p').hidden).toBe(true);
    expect(formDraft('question:review')).toBeUndefined();
});

it.each(['expectedReviewId', 'expectedUrl'])(
    'keeps the original %s after a rejected review and form replacement',
    async (name) => {
        const review = (
            value,
        ) => `<form data-controller="form-draft" data-form-draft-owner-value="${owner}" data-form-draft-key-value="question:review">
            <input type="hidden" name="${name}" value="${value}" data-form-draft-target="guard">
            <textarea data-form-draft-target="text"></textarea>
            <p hidden data-form-draft-target="stale"></p>
        </form>`;
        let controller = await mount(review('original'));
        controller.textTarget.value = 'Review note';
        controller.remember();
        controller.start();
        controller.response({
            detail: { fetchResponse: { succeeded: false } },
        });
        controller = await mount(review('original'));
        expect(controller.element.querySelector('p').hidden).toBe(true);
        controller = await mount(review('changed'));
        expect(controller.textTarget.value).toBe('Review note');
        expect(controller.element.querySelector('input').value).toBe(
            'original',
        );
        expect(controller.element.querySelector('p').hidden).toBe(false);
    },
);
