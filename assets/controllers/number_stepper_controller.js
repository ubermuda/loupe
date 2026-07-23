import { Controller } from '@hotwired/stimulus';

/*
 * A − / + stepper around a number input. The buttons clamp to the input's
 * `min`/`max`. The field is optional: stepping down from the minimum (or from
 * an empty field) clears it, and stepping up from empty jumps to the minimum,
 * so the full range "no value ↔ min ↔ …" stays reachable without typing.
 */
export default class extends Controller {
    static targets = ['input'];

    decrement() {
        this._step(-1);
    }

    increment() {
        this._step(1);
    }

    _step(direction) {
        const input = this.inputTarget;
        const minimum = input.min === '' ? 1 : Number(input.min);
        const maximum = input.max === '' ? Infinity : Number(input.max);
        const hasValue = input.value !== '';
        const current = hasValue ? Number(input.value) : 0;

        let next;
        if (direction < 0) {
            next = current <= minimum ? '' : String(current - 1);
        } else {
            next = hasValue
                ? String(Math.min(maximum, current + 1))
                : String(minimum);
        }

        input.value = next;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
