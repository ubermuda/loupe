import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static values = { url: String };

    // A link to one request names it in the fragment, and the queue that holds
    // it can have changed since the link was written. The open queue hands such
    // a fragment to the completed one, once, so an old link still arrives.
    connect() {
        const hash = window.location.hash;
        if (!/^#inbox-(item|ask)-/.test(hash)) {
            return;
        }

        if (document.getElementById(hash.slice(1))) {
            return;
        }

        window.location.replace(`${this.urlValue}${hash}`);
    }
}
