import { Controller } from '@hotwired/stimulus';

/*
 * Submits the form on ⌘⏎ / Ctrl+⏎.
 *
 * Bind the action on the <form> itself and let keydown bubble up from the
 * textarea: the fields are rendered by form_widget(), so there is nowhere to
 * hang a per-field attribute without reshaping the FormType.
 *
 * requestSubmit() rather than submit() — it fires the submit event, which both
 * Turbo and the eager CSRF controller depend on.
 *
 * Eager, not lazy: a lazy controller is fetched asynchronously, so a reviewer
 * who types their comment and hits ⌘⏎ straight away can land in the gap before
 * connect() runs, and the keystroke does nothing. A shortcut that works only
 * after an invisible delay is worse than none.
 */
/* stimulusFetch: 'eager' */
export default class extends Controller {
    submit(event) {
        if (!event.metaKey && !event.ctrlKey) {
            return;
        }

        if ('Enter' !== event.key) {
            return;
        }

        // The thread's Resolve and Delete buttons sit inside the reply form in
        // the DOM but belong to their own forms via form=. Their keydown still
        // bubbles through here, so without this the shortcut would post the
        // reply while the user was focused on Resolve. A control's .form is the
        // form that owns it, not the one it happens to nest in.
        if (event.target.form !== this.element) {
            return;
        }

        event.preventDefault();
        this.element.requestSubmit();
    }
}
