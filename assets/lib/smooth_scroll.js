/**
 * Eases an element into view inside whatever scrolls it.
 *
 * Hand-rolled rather than `behavior: 'smooth'` because a browser with smooth
 * scrolling switched off drops that request entirely and never moves; writing
 * `scrollTop` per frame is unaffected by that setting.
 */

const MINIMUM_DURATION = 250;
const MAXIMUM_DURATION = 400;
const MILLISECONDS_PER_PIXEL = 0.08;

export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function scrollerFor(element) {
    for (let node = element.parentElement; node; node = node.parentElement) {
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

/**
 * `align` is 'center' or 'start'. A start alignment honours the element's own
 * `scroll-margin-top`, which is what keeps a heading clear of the sticky bar.
 */
export function targetScrollTop(scroller, element, align) {
    // The document scroller has no box of its own to measure against: its rect
    // top moves with the scroll, while the viewport's stays at zero.
    const isDocumentScroller =
        scroller === document.scrollingElement ||
        scroller === document.documentElement;
    const scrollerTop = isDocumentScroller
        ? 0
        : scroller.getBoundingClientRect().top;

    const offset = element.getBoundingClientRect().top - scrollerTop;

    let wanted;
    if ('center' === align) {
        wanted =
            scroller.scrollTop +
            offset -
            (scroller.clientHeight - element.offsetHeight) / 2;
    } else {
        const margin =
            parseFloat(window.getComputedStyle(element).scrollMarginTop) || 0;
        wanted = scroller.scrollTop + offset - margin;
    }

    return Math.max(
        0,
        Math.min(wanted, scroller.scrollHeight - scroller.clientHeight),
    );
}

/**
 * Returns a function that stops the animation. Call the previous one before
 * starting the next, so holding a key tracks the newest target instead of
 * queueing behind it. `onDone` runs when the element has arrived, and never
 * when the scroll was cancelled.
 */
export function smoothScrollTo(element, { align = 'start', onDone } = {}) {
    const scroller = scrollerFor(element);
    const from = scroller.scrollTop;
    const target = targetScrollTop(scroller, element, align);
    const distance = Math.abs(target - from);

    if (distance < 1 || prefersReducedMotion()) {
        scroller.scrollTop = target;
        onDone?.();

        return () => {};
    }

    const duration = Math.min(
        MAXIMUM_DURATION,
        MINIMUM_DURATION + distance * MILLISECONDS_PER_PIXEL,
    );
    const startedAt = performance.now();
    let frame = null;

    const step = (now) => {
        const progress = Math.min(1, (now - startedAt) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        scroller.scrollTop = from + (target - from) * eased;
        frame = progress < 1 ? requestAnimationFrame(step) : null;
        if (null === frame) {
            onDone?.();
        }
    };

    frame = requestAnimationFrame(step);

    return () => {
        if (null !== frame) {
            cancelAnimationFrame(frame);
            frame = null;
        }
    };
}
