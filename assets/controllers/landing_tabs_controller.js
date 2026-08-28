import { Controller } from '@hotwired/stimulus';

const ADVANCE_INTERVAL = 6000;

export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.index = 0;
        this.timer = null;
        this.startedAt = 0;
        this.remaining = ADVANCE_INTERVAL;
        this.visible = false;
        this.held = false;
        this.stopped = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        this.render();

        // Nothing should cycle off-screen: a reader arriving at the section
        // otherwise finds it mid-rotation on whichever panel the timer landed.
        this.observer = new IntersectionObserver(
            (entries) => {
                this.visible = entries.some((entry) => entry.isIntersecting);
                this.sync();
            },
            { threshold: 0.35 },
        );
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer.disconnect();
        this.freeze();
    }

    select(event) {
        this.stopped = true;
        this.freeze();
        this.remaining = ADVANCE_INTERVAL;
        this.show(this.tabTargets.indexOf(event.currentTarget));
    }

    // Pointer or keyboard on the controls means someone is reading a panel they
    // have not chosen yet, so the countdown must not take it away from them.
    hold() {
        this.held = true;
        this.sync();
    }

    release() {
        this.held = false;
        this.sync();
    }

    sync() {
        if (this.visible && !this.held && !this.stopped) {
            this.start();
        } else {
            this.freeze();
        }
        this.render();
    }

    start() {
        if (this.timer !== null) {
            return;
        }
        this.startedAt = Date.now();
        this.timer = setTimeout(() => {
            this.timer = null;
            this.remaining = ADVANCE_INTERVAL;
            this.show((this.index + 1) % this.panelTargets.length);
        }, this.remaining);
    }

    // Keeps what is left of the interval, so a hold resumes the countdown where
    // it stopped rather than replaying it — which is what the filling tile,
    // paused mid-sweep, promises the reader.
    freeze() {
        if (this.timer === null) {
            return;
        }
        clearTimeout(this.timer);
        this.timer = null;
        this.remaining = Math.max(
            0,
            this.remaining - (Date.now() - this.startedAt),
        );
    }

    render() {
        if (this.stopped) {
            this.element.dataset.running = 'false';
        } else {
            this.element.dataset.running =
                this.timer === null ? 'paused' : 'true';
        }
    }

    show(index) {
        this.index = index;
        this.tabTargets.forEach((tab, position) => {
            tab.setAttribute(
                'aria-selected',
                position === index ? 'true' : 'false',
            );
        });
        this.panelTargets.forEach((panel, position) => {
            panel.hidden = position !== index;
        });
        this.sync();
    }
}
