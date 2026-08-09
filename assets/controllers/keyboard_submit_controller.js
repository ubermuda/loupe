import { Controller } from '@hotwired/stimulus';

/**
 * Submits the form on ⌘⏎ / Ctrl+⏎, bound on the <form> so keydown bubbles up
 * from the form_widget()-rendered textarea.
 *
 * requestSubmit() rather than submit(), so the submit event fires for Turbo and
 * the eager CSRF controller. Eager, not lazy: a lazily fetched controller leaves
 * a gap in which the shortcut silently does nothing.
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
