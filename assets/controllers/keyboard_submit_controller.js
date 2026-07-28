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
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    submit(event) {
        if (!event.metaKey && !event.ctrlKey) {
            return;
        }

        if ('Enter' !== event.key) {
            return;
        }

        event.preventDefault();
        this.element.requestSubmit();
    }
}
