import { Controller } from '@hotwired/stimulus';
import { scrollerFor } from '../lib/smooth_scroll.js';

export default class extends Controller {
    static targets = [
        'row',
        'query',
        'family',
        'empty',
        'feed',
        'status',
        'toggle',
        'noEvents',
        'gap',
        'count',
        'filters',
    ];
    static values = {
        url: String,
        project: String,
        labels: Object,
        pageSize: Number,
    };

    connect() {
        this.paused = false;
        this.generation = (this.generation ?? 0) + 1;
        this.hasRefreshed = false;
        this.toggleTarget.disabled = false;
        this.toggleTarget.textContent = this.labelsValue.pause;
        this.toggleTarget.setAttribute('aria-pressed', 'false');
        this.setStatus('connecting');
        this.refresh();
    }

    disconnect() {
        this.stop();
    }

    stop() {
        this.generation++;
        clearTimeout(this.timer);
        this.request?.abort();
    }

    toggle() {
        this.paused = !this.paused;
        this.stop();
        this.toggleTarget.textContent =
            this.labelsValue[this.paused ? 'resume' : 'pause'];
        this.toggleTarget.setAttribute('aria-pressed', String(this.paused));
        if (this.paused) {
            this.setStatus('paused');
        } else {
            this.setStatus('connecting');
            this.refresh();
        }
    }

    async refresh() {
        if (this.paused) return;
        clearTimeout(this.timer);
        const generation = this.generation;
        const request = new AbortController();
        this.request = request;
        const timeout = setTimeout(() => request.abort(), 15000);
        try {
            // eslint-disable-next-line no-restricted-syntax -- Read-only refresh preserves mounted rows; Turbo replacement would discard focus.
            const response = await fetch(this.urlValue, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: request.signal,
                headers: { Accept: 'text/html' },
            });
            if (!response.ok || response.redirected)
                throw new Error('Activity unavailable');
            const document = new DOMParser().parseFromString(
                await response.text(),
                'text/html',
            );
            const snapshot = document.querySelector(
                '[data-activity-filter-project-value]',
            );
            if (
                snapshot?.dataset.activityFilterProjectValue !==
                this.projectValue
            )
                throw new Error('Activity project mismatch');
            if (generation !== this.generation || this.paused) return;
            this.reconcile(snapshot);
            this.hasRefreshed = true;
            this.setStatus('listening');
        } catch {
            if (generation === this.generation && !this.paused) {
                this.setStatus(this.hasRefreshed ? 'stale' : 'failed');
            }
        } finally {
            clearTimeout(timeout);
            if (generation === this.generation && !this.paused) {
                this.timer = setTimeout(() => this.refresh(), 10000);
            }
        }
    }

    reconcile(snapshot) {
        const rows = [...snapshot.querySelectorAll('[data-activity-event-id]')];
        const existing = new Map(
            this.rowTargets.map((row) => [row.dataset.activityEventId, row]),
        );
        const scroller = scrollerFor(this.feedTarget);
        const scrollerTop =
            scroller === document.scrollingElement ||
            scroller === document.documentElement
                ? 0
                : scroller.getBoundingClientRect().top;
        const scrollerBottom = scrollerTop + scroller.clientHeight;
        const anchor = this.rowTargets.find(
            (row) =>
                !row.hidden &&
                row.getBoundingClientRect().bottom > scrollerTop &&
                row.getBoundingClientRect().top < scrollerBottom,
        );
        const anchorTop = anchor?.getBoundingClientRect().top;
        const activeElement = document.activeElement;
        if (
            existing.size > 0 &&
            rows.length === this.pageSizeValue &&
            !rows.some((row) => existing.has(row.dataset.activityEventId))
        ) {
            this.gapTarget.hidden = false;
        }
        const additions = document.createDocumentFragment();
        for (const incoming of rows) {
            const current = existing.get(incoming.dataset.activityEventId);
            if (current) {
                if (!current.contains(activeElement))
                    current.innerHTML = incoming.innerHTML;
            } else {
                additions.append(incoming);
                existing.set(incoming.dataset.activityEventId, incoming);
            }
        }
        this.feedTarget.prepend(additions);
        this.filter();
        this.noEventsTarget.hidden = this.rowTargets.length > 0;
        this.filtersTargets.forEach((filters) => {
            filters.hidden = this.rowTargets.length === 0;
        });
        if (anchor && anchorTop !== undefined) {
            scroller.scrollTop +=
                anchor.getBoundingClientRect().top - anchorTop;
        }
    }

    setStatus(status) {
        this.element.dataset.activityState = status;
        this.statusTarget.textContent = this.labelsValue[status];
        this.statusTarget.title =
            status === 'listening' ? this.labelsValue.listeningTitle : '';
        this.statusTarget.classList.toggle(
            'lp-status-chip--ok',
            status === 'listening',
        );
        this.statusTarget.classList.toggle(
            'lp-status-chip--failed',
            status === 'failed',
        );
        this.statusTarget.classList.toggle(
            'lp-status-chip--pending',
            !['listening', 'failed'].includes(status),
        );
    }

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

        this.emptyTarget.hidden =
            visibleCount > 0 || this.rowTargets.length === 0;
        this.countTargets.forEach((count) => {
            count.textContent = `${visibleCount} / ${this.rowTargets.length}`;
        });
    }
}
