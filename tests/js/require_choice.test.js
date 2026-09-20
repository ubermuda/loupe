/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it } from 'vitest';
import RequireChoiceController from '../../assets/controllers/require_choice_controller.js';

let application;

beforeEach(() => {
    application = Application.start();
    application.register('require-choice', RequireChoiceController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await new Promise((resolve) => setTimeout(resolve, 0));
    application.stop();
});

async function mount(markup) {
    document.body.innerHTML = markup;
    await new Promise((resolve) => setTimeout(resolve, 0));
}

const withChoice = `<form data-controller="require-choice">
    <select data-require-choice-target="choice" data-action="change->require-choice#check">
        <option value="">Choose a project</option>
        <option value="a-project-id">Riley site</option>
    </select>
    <button data-require-choice-target="submit">Allow</button>
</form>`;

it('keeps the submit disabled until a choice is made', async () => {
    await mount(withChoice);
    const select = document.querySelector('select');
    const submit = document.querySelector('button');

    expect(submit.disabled).toBe(true);

    select.value = 'a-project-id';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    expect(submit.disabled).toBe(false);

    select.value = '';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    expect(submit.disabled).toBe(true);
});

it('leaves the submit alone when the form has no choice', async () => {
    await mount(`<form data-controller="require-choice">
        <button data-require-choice-target="submit">Allow</button>
    </form>`);

    expect(document.querySelector('button').disabled).toBe(false);
});
