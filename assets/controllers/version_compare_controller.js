import { Controller } from '@hotwired/stimulus';

/**
 * The diff page's version picker: two selects and a submit that visit the diff
 * of any pair, not only the adjacent one the version switcher links to.
 *
 * Usage:
 *   <form data-controller="version-compare"
 *         data-version-compare-url-value="/…/diff/1/2"
 *         data-action="submit->version-compare#compare change->version-compare#sync">
 *     <select data-version-compare-target="from"> … </select>
 *     <select data-version-compare-target="to"> … </select>
 */
export default class extends Controller {
    static targets = ['from', 'to'];
    static values = { url: String };

    connect() {
        this.sync();
    }

    /**
     * A diff runs forwards, and the route answers 404 for any other pair, so the
     * later select never offers a version at or before the earlier one.
     */
    sync() {
        const from = Number(this.fromTarget.value);
        for (const option of this.toTarget.options) {
            option.disabled = Number(option.value) <= from;
        }

        if (this.toTarget.selectedOptions[0]?.disabled) {
            const firstComparable = Array.from(this.toTarget.options).find(
                (option) => !option.disabled,
            );
            if (firstComparable) {
                this.toTarget.value = firstComparable.value;
            }
        }
    }

    compare(event) {
        event.preventDefault();

        // Unreachable while the selects offer only comparable versions, and the
        // guard is what keeps it that way: the route answers 404, not a page.
        if (this.toTarget.selectedOptions[0]?.disabled) {
            return;
        }

        // The two version numbers are the route's last two path segments, so the
        // page's own URL doubles as the template and nothing here has to know
        // how the rest of the path is built. The lookahead is what lets that URL
        // carry a query string, which is where the choice of diff view lives.
        const url = this.urlValue.replace(
            /\/\d+\/\d+(?=\?|$)/,
            `/${this.fromTarget.value}/${this.toTarget.value}`,
        );

        if (window.Turbo) {
            window.Turbo.visit(url);
        } else {
            window.location.assign(url);
        }
    }
}
