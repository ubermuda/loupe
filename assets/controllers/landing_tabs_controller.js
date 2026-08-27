import { Controller } from '@hotwired/stimulus';

const ADVANCE_INTERVAL = 6000;

export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.index = 0;
        this.timer = null;
        this.visible = false;
        this.held = false;
        this.stopped = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

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
        this.pause();
    }

    select(event) {
        this.stopped = true;
        this.pause();
        this.show(this.tabTargets.indexOf(event.currentTarget));
    }

    // Pointer or keyboard on the controls means someone is reading a panel they
    // have not chosen yet, so the timer must not take it away from them.
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
            this.pause();
        }
    }

    start() {
        if (this.timer !== null) {
            return;
        }
        this.timer = setInterval(() => {
            this.show((this.index + 1) % this.panelTargets.length);
        }, ADVANCE_INTERVAL);
        this.restartCountdown();
    }

    pause() {
        clearInterval(this.timer);
        this.timer = null;
        this.element.dataset.running = 'false';
    }

    // The countdown is a CSS animation on the selected tab's rule, and a new
    // interval always starts from zero — so resuming after a hold has to replay
    // it rather than pick up where the freeze left off.
    restartCountdown() {
        this.element.dataset.running = 'false';
        void this.element.offsetWidth;
        this.element.dataset.running = 'true';
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
    }
}
