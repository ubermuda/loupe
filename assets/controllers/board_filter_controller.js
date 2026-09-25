import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['query', 'card', 'row', 'count', 'empty'];

    cardTargetConnected() {
        this.#scheduleFilter();
    }

    rowTargetConnected() {
        this.#scheduleFilter();
    }

    /** One pass for all the targets that connect together, such as a whole board. */
    #scheduleFilter() {
        if (this.filterScheduled) {
            return;
        }
        this.filterScheduled = true;
        queueMicrotask(() => {
            this.filterScheduled = false;
            if (
                this.hasQueryTarget &&
                this.hasCountTarget &&
                this.hasEmptyTarget
            ) {
                this.filter();
            }
        });
    }

    revealField(event) {
        event.target.scrollIntoView({
            block: 'nearest',
            inline: 'nearest',
            behavior: 'instant',
        });
    }

    filter() {
        const query = this.queryTarget.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        for (const card of this.cardTargets) {
            const visible =
                query === '' ||
                card.dataset.cardTitle.toLocaleLowerCase().includes(query);

            card.hidden = !visible;
            if (visible) {
                visibleCount += 1;
            }
        }

        for (const row of this.rowTargets) {
            row.hidden =
                query !== '' &&
                !row.dataset.cardTitle.toLocaleLowerCase().includes(query);
        }

        this.countTarget.textContent =
            visibleCount === 1
                ? this.countTarget.dataset.one
                : this.countTarget.dataset.many.replace(
                      '%count%',
                      String(visibleCount),
                  );
        this.emptyTarget.hidden = visibleCount !== 0 || query === '';
    }
}
