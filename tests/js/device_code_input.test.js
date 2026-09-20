/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import DeviceCodeInputController from '../../assets/controllers/device_code_input_controller.js';

let application;
let submitted;

async function mount(value = '') {
    document.body.innerHTML = `
        <form action="/oauth/device" method="post">
            <div class="lp-form-field"
                 data-controller="device-code-input"
                 data-device-code-input-length-value="8"
                 data-device-code-input-group-value="4"
                 data-device-code-input-character-label-value="Character %position% of %count%">
                <label id="device-code-label" for="user-code">Code</label>
                <input id="user-code" name="device_code_entry_form[userCode]" required value="${value}" data-device-code-input-target="field">
                <div class="lp-code-field" role="group" aria-labelledby="device-code-label" data-device-code-input-target="boxes" hidden></div>
            </div>
            <button type="submit" id="continue">Continue</button>
        </form>`;
    const form = document.querySelector('form');
    submitted = vi.fn();
    form.requestSubmit = submitted;
    await new Promise((resolve) => setTimeout(resolve, 0));

    return application.getControllerForElementAndIdentifier(
        document.querySelector('[data-controller="device-code-input"]'),
        'device-code-input',
    );
}

function boxes() {
    return [...document.querySelectorAll('.lp-code-field__box')];
}

function field() {
    return document.getElementById('user-code');
}

function paste(box, text) {
    const event = new Event('paste', { bubbles: true, cancelable: true });
    event.clipboardData = { getData: () => text };
    box.dispatchEvent(event);
}

function type(box, character) {
    box.value = character;
    box.dispatchEvent(new Event('input', { bubbles: true }));
}

function press(box, key) {
    box.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
    );
}

beforeEach(() => {
    application = Application.start();
    application.register('device-code-input', DeviceCodeInputController);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
});

it('replaces the plain field with one box per character', async () => {
    await mount();

    expect(boxes()).toHaveLength(8);
    expect(document.querySelectorAll('.lp-code-field__separator')).toHaveLength(
        1,
    );
    expect(boxes()[0].getAttribute('aria-label')).toBe('Character 1 of 8');
    expect(field().hidden).toBe(true);
    // A hidden required field would stop the browser from submitting the form.
    expect(field().required).toBe(false);
});

it('fills every box from a pasted code and submits', async () => {
    await mount();

    paste(boxes()[0], 'BCDFGHJK');

    expect(
        boxes()
            .map((box) => box.value)
            .join(''),
    ).toBe('BCDFGHJK');
    expect(field().value).toBe('BCDFGHJK');
    expect(submitted).toHaveBeenCalledTimes(1);
    expect(submitted.mock.calls[0][0].id).toBe('continue');
});

it('reads a pasted code with a dash, spaces or lower case', async () => {
    await mount();

    paste(boxes()[0], '  bcdf-ghjk \n');

    expect(field().value).toBe('BCDFGHJK');
    expect(submitted).toHaveBeenCalledTimes(1);
});

it('starts a full code at the first box, wherever it is pasted', async () => {
    await mount();

    paste(boxes()[5], 'BCDF-GHJK');

    expect(field().value).toBe('BCDFGHJK');
});

it('does not submit a part of a code', async () => {
    await mount();

    paste(boxes()[0], 'BCDF');

    expect(field().value).toBe('BCDF');
    expect(submitted).not.toHaveBeenCalled();
});

it('never submits while the code is typed', async () => {
    await mount();
    const typedBoxes = boxes();

    'BCDFGHJK'
        .split('')
        .forEach((character, index) => type(typedBoxes[index], character));

    expect(field().value).toBe('BCDFGHJK');
    expect(submitted).not.toHaveBeenCalled();
});

it('moves on as each character is typed, and back on backspace', async () => {
    await mount();

    type(boxes()[0], 'b');
    expect(boxes()[0].value).toBe('B');
    expect(document.activeElement).toBe(boxes()[1]);

    press(boxes()[1], 'Backspace');
    expect(boxes()[0].value).toBe('');
    expect(document.activeElement).toBe(boxes()[0]);
});

it('moves with the arrow keys', async () => {
    await mount();

    press(boxes()[0], 'ArrowRight');
    expect(document.activeElement).toBe(boxes()[1]);

    press(boxes()[1], 'ArrowLeft');
    expect(document.activeElement).toBe(boxes()[0]);
});

it('shows a code the server sent back and restores the plain field on disconnect', async () => {
    const controller = await mount('bcdf-ghjk');

    expect(
        boxes()
            .map((box) => box.value)
            .join(''),
    ).toBe('BCDFGHJK');

    controller.disconnect();

    expect(boxes()).toHaveLength(0);
    expect(field().hidden).toBe(false);
    expect(field().required).toBe(true);
});
