import { Controller } from '@hotwired/stimulus';

const MINIMUM_SCROLL_DURATION = 250;
const MAXIMUM_SCROLL_DURATION = 400;
const SCROLL_MILLISECONDS_PER_PIXEL = 0.08;

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
        this.animationFrame = null;
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        this.cancelScroll();
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
        this.scrollToHunk(hunk);

        if (this.hasCounterTarget) {
            this.counterTarget.textContent = this.positionValue.replace(
                '%current%',
                String(this.currentIndex + 1),
            );
        }
    }

    /**
     * Eases the hunk to the middle of its scroller, so the reader sees where
     * they were taken rather than arriving with no sense of the distance.
     *
     * Hand-rolled rather than `behavior: 'smooth'` because a browser with
     * smooth scrolling switched off drops that request entirely and never
     * moves; writing `scrollTop` per frame is unaffected by that setting.
     */
    scrollToHunk(hunk) {
        // Each press restarts from wherever the last animation reached, so
        // holding `j` tracks the newest target instead of queueing behind it.
        this.cancelScroll();

        const scroller = this.scrollerFor(hunk);
        const from = scroller.scrollTop;
        const target = this.centeredScrollTop(scroller, hunk);
        const distance = Math.abs(target - from);

        if (distance < 1 || this.prefersReducedMotion()) {
            scroller.scrollTop = target;

            return;
        }

        const duration = Math.min(
            MAXIMUM_SCROLL_DURATION,
            MINIMUM_SCROLL_DURATION + distance * SCROLL_MILLISECONDS_PER_PIXEL,
        );
        const startedAt = performance.now();

        const step = (now) => {
            const progress = Math.min(1, (now - startedAt) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            scroller.scrollTop = from + (target - from) * eased;
            this.animationFrame =
                progress < 1 ? requestAnimationFrame(step) : null;
        };

        this.animationFrame = requestAnimationFrame(step);
    }

    cancelScroll() {
        if (null !== this.animationFrame) {
            cancelAnimationFrame(this.animationFrame);
            this.animationFrame = null;
        }
    }

    prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    scrollerFor(element) {
        for (
            let node = element.parentElement;
            node;
            node = node.parentElement
        ) {
            const overflowY = window.getComputedStyle(node).overflowY;
            if (
                ('auto' === overflowY || 'scroll' === overflowY) &&
                node.scrollHeight > node.clientHeight
            ) {
                return node;
            }
        }

        return document.scrollingElement ?? document.documentElement;
    }

    centeredScrollTop(scroller, element) {
        // The document scroller has no box of its own to measure against: its
        // rect top moves with the scroll, while the viewport's stays at zero.
        const isDocumentScroller =
            scroller === document.scrollingElement ||
            scroller === document.documentElement;
        const scrollerTop = isDocumentScroller
            ? 0
            : scroller.getBoundingClientRect().top;

        const offset = element.getBoundingClientRect().top - scrollerTop;
        const centered =
            scroller.scrollTop +
            offset -
            (scroller.clientHeight - element.offsetHeight) / 2;

        return Math.max(
            0,
            Math.min(centered, scroller.scrollHeight - scroller.clientHeight),
        );
    }

    clearCurrent() {
        for (const hunk of this.hunkTargets) {
            hunk.classList.remove('lp-diff__hunk--current');
        }
    }
}
