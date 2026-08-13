/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/*
 * Scales the hero's app still to the available width. It is laid out at the
 * app's real dimensions, so it can only be fitted by transform, not by reflow.
 */
const MOCK_WIDTH = 1260;
const MOCK_HEIGHT = 640;
const MAX_SCALE = 0.72;

export default class extends Controller {
    static targets = ['frame'];

    connect() {
        // ResizeObserver rather than connect(): during a Turbo navigation
        // connect() runs before layout, where clientWidth is still 0.
        this.resizeObserver = new ResizeObserver(() => this.fit());
        this.resizeObserver.observe(this.element);
    }

    disconnect() {
        this.resizeObserver.disconnect();
    }

    fit() {
        const available = this.element.clientWidth;
        if (!available) {
            return;
        }

        const scale = Math.min(MAX_SCALE, available / MOCK_WIDTH);
        // This observer watches the element whose height the write below sets,
        // so re-writing an unchanged value is what starts an observer loop.
        if (scale === this.lastScale) {
            return;
        }

        this.lastScale = scale;
        this.frameTarget.style.transform = `translateX(-50%) scale(${scale})`;
        this.element.style.height = `${Math.round(MOCK_HEIGHT * scale)}px`;
    }
}
