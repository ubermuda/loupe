import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['query', 'card', 'row', 'count', 'empty', 'laneHead'];

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
        const matchedLanes = new Set();

        for (const card of this.cardTargets) {
            const visible =
                query === '' ||
                card.dataset.cardTitle.toLocaleLowerCase().includes(query);

            card.hidden = !visible;
            if (visible) {
                visibleCount += 1;
                if (query !== '') {
                    matchedLanes.add(card.closest('.lp-board-lane'));
                }
            }
        }

        // A search opens a collapsed lane that holds a match, and leaves the
        // stored collapse alone, so the lane closes again when it clears.
        for (const lane of this.element.querySelectorAll('.lp-board-lane')) {
            lane.classList.toggle(
                'lp-board-lane--revealed',
                matchedLanes.has(lane),
            );
        }

        // A lane epic is its header rather than a card. The header always
        // stays, and a matching title counts it once.
        if (query !== '') {
            for (const head of this.laneHeadTargets) {
                if (
                    head.dataset.cardTitle.toLocaleLowerCase().includes(query)
                ) {
                    visibleCount += 1;
                }
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
