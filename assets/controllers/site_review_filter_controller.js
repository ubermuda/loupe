import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['row', 'panel', 'query', 'status', 'scope', 'empty'];

    /* Read from the pressed tab, so a re-rendered list cannot leave a stale scope behind. */
    get selectedScope() {
        return (
            this.scopeTargets.find(
                (control) => control.getAttribute('aria-pressed') === 'true',
            )?.dataset.siteReviewFilterScopeParam ?? 'all'
        );
    }

    filter() {
        const query = this.queryTarget.value.trim().toLocaleLowerCase();
        const status = this.statusTarget.value;
        let firstVisible = null;

        for (const row of this.rowTargets) {
            const visible =
                (this.selectedScope === 'all' ||
                    row.dataset.linked === 'false') &&
                (status === 'all' || row.dataset.status === status) &&
                (query === '' ||
                    row.textContent.toLocaleLowerCase().includes(query));
            row.hidden = !visible;
            if (visible && firstVisible === null) {
                firstVisible = row;
            }
        }

        for (const panel of this.panelTargets) {
            panel.hidden = true;
        }
        this.emptyTarget.hidden = firstVisible !== null;
        firstVisible?.click();
    }

    setScope(event) {
        for (const control of this.scopeTargets) {
            control.setAttribute(
                'aria-pressed',
                String(
                    control.dataset.siteReviewFilterScopeParam ===
                        event.params.scope,
                ),
            );
        }
        this.filter();
    }
}
