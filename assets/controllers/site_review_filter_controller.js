import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['row', 'panel', 'query', 'status', 'scope', 'empty'];

    connect() {
        this.scope = 'all';
    }

    filter() {
        const query = this.queryTarget.value.trim().toLocaleLowerCase();
        const status = this.statusTarget.value;
        let firstVisible = null;

        for (const row of this.rowTargets) {
            const visible =
                (this.scope === 'all' || row.dataset.linked === 'false') &&
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
        this.scope = event.params.scope;
        for (const control of this.scopeTargets) {
            control.setAttribute(
                'aria-pressed',
                String(
                    control.dataset.siteReviewFilterScopeParam === this.scope,
                ),
            );
        }
        this.filter();
    }
}
