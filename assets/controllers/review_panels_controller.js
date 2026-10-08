import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'loupe.review.panels';
const PANEL_ORDER = ['decisions', 'comments', 'outline'];
const DEFAULT_PANELS = ['decisions'];

export default class extends Controller {
    static targets = [
        'button',
        'panel',
        'filter',
        'thread',
        'option',
        'empty',
        'count',
    ];

    connect() {
        const stored = this.#storedPanels();
        if (stored !== null) {
            for (const button of this.buttonTargets) {
                const name = button.dataset.reviewPanelsNameParam;
                this.#show(name, stored.includes(name));
            }
        }

        this.activeFilter = this.element.classList.contains(
            'lp-review-block--hide-resolved',
        )
            ? 'open'
            : 'all';
        this.refreshFilter();
    }

    threadTargetConnected() {
        this.refreshFilter();
    }

    threadTargetDisconnected() {
        this.refreshFilter();
    }

    emptyTargetConnected() {
        this.refreshFilter();
    }

    toggle(event) {
        const button = event.currentTarget;
        if (this.#isDisabled(button)) {
            return;
        }
        const name = event.params.name;
        const open = button.getAttribute('aria-pressed') !== 'true';
        this.#show(name, open);

        // Stored choices for a panel this page lacks or disables stay as they
        // were, so a comparison does not close Decisions on the document.
        const panels = new Set(this.#storedPanels() ?? DEFAULT_PANELS);
        if (open) {
            panels.add(name);
        } else {
            panels.delete(name);
        }
        try {
            window.localStorage.setItem(
                STORAGE_KEY,
                JSON.stringify(PANEL_ORDER.filter((each) => panels.has(each))),
            );
        } catch {
            // Storage can be blocked. The toggle still works on this page.
        }
        window.dispatchEvent(new Event('resize'));
    }

    filter(event) {
        this.activeFilter = event.params.filter;
        this.dispatch('filter', { detail: { filter: this.activeFilter } });
        this.refreshFilter();
        this.filterTarget.open = false;
        this.filterTarget.querySelector('summary').focus();
    }

    resolvedFilter(event) {
        this.activeFilter = event.detail.hidden ? 'open' : 'all';
        this.refreshFilter();
    }

    closeFilter(event) {
        if (!this.filterTarget.open) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        this.filterTarget.open = false;
        this.filterTarget.querySelector('summary').focus();
    }

    refreshFilter() {
        if (!this.hasFilterTarget || !this.activeFilter) {
            return;
        }
        const counts = { all: 0, open: 0, resolved: 0, unanchored: 0 };

        for (const thread of this.threadTargets) {
            const status = thread.dataset.anchorStatus;
            const matches = [
                'all',
                status === 'resolved' ? 'resolved' : 'open',
            ];
            if (thread.dataset.commentOrphaned === 'true') {
                matches.push('unanchored');
            }
            for (const match of matches) {
                counts[match]++;
            }
            thread.hidden = !matches.includes(this.activeFilter);
        }

        for (const option of this.optionTargets) {
            const name = option.dataset.reviewPanelsFilterParam;
            option.setAttribute(
                'aria-pressed',
                name === this.activeFilter ? 'true' : 'false',
            );
            option.querySelector('[data-filter-count]').textContent =
                counts[name];
        }
        for (const count of this.countTargets) {
            count.textContent = counts.all;
        }
        this.filterTarget
            .querySelector('summary')
            .classList.toggle(
                'lp-review-filter__toggle--filtered',
                this.activeFilter !== 'open',
            );
        for (const empty of this.emptyTargets) {
            empty.hidden = counts[this.activeFilter] !== 0;
            for (const message of empty.querySelectorAll(
                '[data-empty-filter]',
            )) {
                // Either the document holds no comments at all, or a filter
                // is hiding the ones it holds.
                message.hidden =
                    (message.dataset.emptyFilter === 'all') !==
                    (counts.all === 0);
            }
        }
        for (const group of this.element.querySelectorAll(
            '.lp-general-comments, .lp-orphan-group',
        )) {
            group.hidden = !group.querySelector(
                '.lp-comment-thread:not([hidden])',
            );
        }
        window.dispatchEvent(new Event('resize'));
    }

    #show(name, open) {
        const button = this.buttonTargets.find(
            (each) => each.dataset.reviewPanelsNameParam === name,
        );
        const visible =
            open && button !== undefined && !this.#isDisabled(button);
        button?.setAttribute('aria-pressed', visible ? 'true' : 'false');
        for (const panel of this.panelTargets) {
            if (panel.dataset.reviewPanel === name) {
                panel.hidden = !visible;
            }
        }
    }

    // aria-disabled rather than disabled, so the button keeps its tooltip.
    #isDisabled(button) {
        return button.getAttribute('aria-disabled') === 'true';
    }

    #storedPanels() {
        try {
            const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY));
            if (
                Array.isArray(stored) &&
                stored.every((name) => PANEL_ORDER.includes(name))
            ) {
                return stored;
            }
        } catch {
            // Unreadable or blocked storage falls back to the server default.
        }

        return null;
    }
}
