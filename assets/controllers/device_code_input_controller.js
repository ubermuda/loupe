/* stimulusFetch: 'eager' */
import { Controller } from '@hotwired/stimulus';

// The user code is letters only, and the page reads it without case, spaces or
// dashes, so a pasted code is cut down to the letters it carries.
function letters(text) {
    return text.toUpperCase().replace(/[^A-Z]/g, '');
}

// One box per character of the device user code. The server renders a plain
// text field, and this controller replaces it, so the page works with no
// JavaScript. A paste fills every box and submits; typing never submits, so a
// half-typed code cannot spend one of the few tries an account gets.
export default class extends Controller {
    static targets = ['field', 'boxes'];
    static values = {
        length: { type: Number, default: 8 },
        group: { type: Number, default: 4 },
        characterLabel: String,
    };

    connect() {
        this.boxes = [];
        for (let index = 0; index < this.lengthValue; index++) {
            if (index > 0 && index % this.groupValue === 0) {
                this.boxesTarget.append(this.buildSeparator());
            }
            const box = this.buildBox(index);
            this.boxes.push(box);
            this.boxesTarget.append(box);
        }

        this.fieldWasRequired = this.fieldTarget.required;
        this.fieldTarget.required = false;
        this.fieldTarget.hidden = true;
        this.boxesTarget.hidden = false;
        this.write(letters(this.fieldTarget.value), 0);
    }

    disconnect() {
        this.boxesTarget.replaceChildren();
        this.boxesTarget.hidden = true;
        this.fieldTarget.hidden = false;
        this.fieldTarget.required = this.fieldWasRequired;
    }

    buildBox(index) {
        const box = document.createElement('input');
        box.type = 'text';
        box.className = 'lp-code-field__box';
        box.inputMode = 'text';
        box.autocomplete = 'off';
        box.spellcheck = false;
        box.maxLength = 1;
        box.setAttribute('autocapitalize', 'characters');
        box.setAttribute(
            'aria-label',
            this.characterLabelValue
                .replace('%position%', String(index + 1))
                .replace('%count%', String(this.lengthValue)),
        );
        box.addEventListener('input', (event) => this.typed(index, event));
        box.addEventListener('keydown', (event) => this.keyed(index, event));
        box.addEventListener('paste', (event) => this.pasted(index, event));
        box.addEventListener('focus', () => box.select());

        return box;
    }

    buildSeparator() {
        const separator = document.createElement('span');
        separator.className = 'lp-code-field__separator';
        separator.setAttribute('aria-hidden', 'true');
        separator.textContent = '-';

        return separator;
    }

    typed(index, event) {
        const typed = letters(event.target.value);
        // More than one character at once is a paste the browser handled, or an
        // autofill, so it fills the boxes rather than only this one.
        if (typed.length > 1) {
            this.write(typed, index, { submitWhenComplete: true });

            return;
        }
        this.boxes[index].value = typed;
        this.sync();
        if (typed !== '') this.focusBox(index + 1);
    }

    keyed(index, event) {
        if (event.key === 'Backspace' && this.boxes[index].value === '') {
            event.preventDefault();
            if (index > 0) {
                this.boxes[index - 1].value = '';
                this.sync();
                this.focusBox(index - 1);
            }

            return;
        }
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            this.focusBox(index - 1);
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            this.focusBox(index + 1);
        }
    }

    pasted(index, event) {
        const pasted = event.clipboardData?.getData('text') ?? '';
        event.preventDefault();
        this.write(letters(pasted), index, { submitWhenComplete: true });
    }

    write(code, from, { submitWhenComplete = false } = {}) {
        // A code that fills every box starts at the first one, however the
        // paste landed, so a paste into the last box still reads correctly.
        const start = code.length >= this.lengthValue ? 0 : from;
        for (let offset = 0; offset < code.length; offset++) {
            const box = this.boxes[start + offset];
            if (!box) break;
            box.value = code[offset];
        }
        this.sync();
        this.focusBox(start + code.length);
        if (submitWhenComplete) this.submitWhenComplete();
    }

    sync() {
        this.fieldTarget.value = this.boxes.map((box) => box.value).join('');
    }

    focusBox(index) {
        const box =
            this.boxes[Math.min(Math.max(index, 0), this.boxes.length - 1)];
        box.focus();
        box.select();
    }

    submitWhenComplete() {
        if (this.fieldTarget.value.length !== this.lengthValue) return;
        const form = this.fieldTarget.form;
        if (!form) return;
        const submitter = form.querySelector(
            'button[type="submit"], input[type="submit"]',
        );
        form.requestSubmit(submitter ?? undefined);
    }
}
