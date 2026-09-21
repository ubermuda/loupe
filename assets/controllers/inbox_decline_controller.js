import { Controller } from '@hotwired/stimulus';

/**
 * Decline carries the note the reader typed in the item's answer or review
 * field, which is the one place an item takes prose. The hidden field belongs
 * to the decline form, so a decline without script simply carries no note.
 */
export default class extends Controller {
    static targets = ['note', 'value'];

    carryNote() {
        if (!this.hasValueTarget) {
            return;
        }
        this.valueTarget.value = this.hasNoteTarget
            ? this.noteTarget.value.trim()
            : '';
    }
}
