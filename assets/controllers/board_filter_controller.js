import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['query', 'priority', 'card', 'row', 'count', 'empty'];

    filter() {
        const query = this.queryTarget.value.trim().toLocaleLowerCase();
        const priority = this.priorityTarget.value;
        let visibleCount = 0;

        for (const card of this.cardTargets) {
            const cardPriority =
                card.closest('[data-priority]')?.dataset.priority;
            const visible =
                (priority === '' || cardPriority === priority) &&
                (query === '' ||
                    card.dataset.cardTitle.toLocaleLowerCase().includes(query));

            card.hidden = !visible;
            if (visible) {
                visibleCount += 1;
            }
        }

        for (const row of this.rowTargets) {
            row.hidden =
                (priority !== '' && row.dataset.cardPriority !== priority) ||
                (query !== '' &&
                    !row.dataset.cardTitle.toLocaleLowerCase().includes(query));
        }

        this.countTarget.textContent =
            visibleCount === 1
                ? this.countTarget.dataset.one
                : this.countTarget.dataset.many.replace(
                      '%count%',
                      String(visibleCount),
                  );
        this.emptyTarget.hidden =
            visibleCount !== 0 || (query === '' && priority === '');
    }
}
