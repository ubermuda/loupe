import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['row', 'query', 'family', 'empty'];

    filter() {
        const query = this.queryTarget.value.trim().toLocaleLowerCase();
        const family = this.familyTarget.value;
        let visibleCount = 0;

        for (const row of this.rowTargets) {
            const visible =
                (family === 'all' || row.dataset.eventFamily === family) &&
                (query === '' ||
                    row.textContent.toLocaleLowerCase().includes(query));
            row.hidden = !visible;
            visibleCount += Number(visible);
        }

        this.emptyTarget.hidden = visibleCount > 0;
    }
}
