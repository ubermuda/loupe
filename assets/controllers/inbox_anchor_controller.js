import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    static values = { queue: String };

    // A link to one request names it in the fragment, and the queue that holds
    // it can have changed since the link was written. This queue hands such a
    // fragment to the other one, once, keeping the page and the search it
    // arrived with, so an old link still lands on the request it names.
    connect() {
        const url = new URL(window.location.href);
        if (!/^#inbox-(item|ask)-/.test(url.hash)) {
            return;
        }

        if (document.getElementById(url.hash.slice(1))) {
            return;
        }

        url.searchParams.set('queue', this.queueValue);
        window.location.replace(url.toString());
    }
}
