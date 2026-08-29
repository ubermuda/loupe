import { Controller } from '@hotwired/stimulus';

/**
 * Jumps a reviewer between the changed hunks of a version diff, by button or by
 * `j` / `k`. The shortcuts are document-level so they work wherever the reader
 * has scrolled, and are inert while a field has focus — a diff view can carry a
 * comment composer, and a bare `j` typed there must reach it.
 *
 * Usage:
 *   <div data-controller="diff-navigation"
 *        data-diff-navigation-position-value="Change %current% of 12">
 *     <p data-diff-navigation-target="counter">12 changes</p>
 *     <button data-action="diff-navigation#previous">…</button>
 *     <button data-action="diff-navigation#next">…</button>
 *     <div data-diff-navigation-target="hunk" tabindex="-1"> … </div>
 */
export default class extends Controller {
    static targets = ['hunk', 'counter'];
    static values = { position: String };

    connect() {
        this.currentIndex = -1;
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        // Turbo caches the page as it stands, so a marker left here would come
        // back on the restored snapshot with nothing driving it.
        this.clearCurrent();
    }

    next() {
        this.moveBy(1);
    }

    previous() {
        this.moveBy(-1);
    }

    handleKeydown(event) {
        if (event.metaKey || event.ctrlKey || event.altKey) {
            return;
        }
        if (this.isTypingTarget(event.target)) {
            return;
        }

        if ('j' === event.key) {
            event.preventDefault();
            this.next();
        } else if ('k' === event.key) {
            event.preventDefault();
            this.previous();
        }
    }

    isTypingTarget(target) {
        if (!(target instanceof HTMLElement)) {
            return false;
        }

        return (
            target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
        );
    }

    moveBy(step) {
        const total = this.hunkTargets.length;
        if (0 === total) {
            return;
        }

        // Nothing is current until the reviewer moves, so the first press lands
        // on the first hunk going forwards and on the last one going backwards.
        if (this.currentIndex < 0) {
            this.currentIndex = step > 0 ? 0 : total - 1;
        } else {
            this.currentIndex = (this.currentIndex + step + total) % total;
        }

        const hunk = this.hunkTargets[this.currentIndex];
        this.clearCurrent();
        hunk.classList.add('lp-diff__hunk--current');
        hunk.focus({ preventScroll: true });
        // 'instant', not 'auto': 'auto' defers to the scroller's CSS
        // scroll-behavior, and a smooth scroll is dropped silently by a browser
        // with smooth scrolling off — leaving the jump with no scroll at all.
        hunk.scrollIntoView({ block: 'center', behavior: 'instant' });

        if (this.hasCounterTarget) {
            this.counterTarget.textContent = this.positionValue.replace(
                '%current%',
                String(this.currentIndex + 1),
            );
        }
    }

    clearCurrent() {
        for (const hunk of this.hunkTargets) {
            hunk.classList.remove('lp-diff__hunk--current');
        }
    }
}
