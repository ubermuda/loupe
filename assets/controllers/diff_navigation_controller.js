import { Controller } from '@hotwired/stimulus';
import { smoothScrollTo } from '../lib/smooth_scroll.js';

/**
 * Jumps a reviewer between the changed hunks of a version diff, by button or by
 * `j` / `k`. The shortcuts are document-level so they work wherever the reader
 * has scrolled, and are inert while a field has focus — a diff view can carry a
 * comment composer, and a bare `j` typed there must reach it.
 *
 * A hunk target is whatever the server marked as the start of a run of
 * changes, which on the rendered diff is an <ins>/<del> that may be inline.
 *
 * Usage:
 *   <div data-controller="diff-navigation"
 *        data-diff-navigation-position-value="Change %current% of 12">
 *     <p data-diff-navigation-target="counter">12 changes</p>
 *     <button data-action="diff-navigation#previous">…</button>
 *     <button data-action="diff-navigation#next">…</button>
 *     <del data-diff-navigation-target="hunk" tabindex="-1"> … </del>
 */
export default class extends Controller {
    static targets = ['hunk', 'counter', 'previousButton', 'nextButton'];
    static values = { position: String };

    /** How long a key press keeps its button lit, in milliseconds. */
    static PRESS_FLASH_MS = 160;

    connect() {
        this.currentIndex = -1;
        this.cancelScroll = () => {};
        this.pressTimers = new Map();
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        this.cancelScroll();
        this.clearPressFlashes();
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
        // composedPath()[0], not event.target: the site-review widget composes in a
        // shadow root, and a keydown retargets to its host — so the field test passed
        // and j/k ate every one the reviewer typed. The widget loads on every
        // authenticated page, so both are always live.
        if (this.isTypingTarget(event.composedPath()[0] ?? event.target)) {
            return;
        }

        if ('j' === event.key) {
            event.preventDefault();
            this.flashPress(
                this.hasNextButtonTarget ? this.nextButtonTarget : null,
            );
            this.next();
        } else if ('k' === event.key) {
            event.preventDefault();
            this.flashPress(
                this.hasPreviousButtonTarget ? this.previousButtonTarget : null,
            );
            this.previous();
        }
    }

    /**
     * Lights the button the key stands for, so the shortcut and the pointer
     * report the same press. Holding the key restarts the timer rather than
     * queueing another, which keeps the button lit for as long as it repeats.
     */
    flashPress(button) {
        if (null === button) {
            return;
        }

        window.clearTimeout(this.pressTimers.get(button));
        button.classList.add('lp-btn--pressed');
        this.pressTimers.set(
            button,
            window.setTimeout(() => {
                button.classList.remove('lp-btn--pressed');
                this.pressTimers.delete(button);
            }, this.constructor.PRESS_FLASH_MS),
        );
    }

    clearPressFlashes() {
        for (const [button, timer] of this.pressTimers) {
            window.clearTimeout(timer);
            button.classList.remove('lp-btn--pressed');
        }
        this.pressTimers.clear();
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
     */
    scrollToHunk(hunk) {
        // Each press restarts from wherever the last animation reached, so
        // holding `j` tracks the newest target instead of queueing behind it.
        this.cancelScroll();
        this.cancelScroll = smoothScrollTo(hunk, { align: 'center' });
    }

    clearCurrent() {
        for (const hunk of this.hunkTargets) {
            hunk.classList.remove('lp-diff__hunk--current');
        }
    }
}
