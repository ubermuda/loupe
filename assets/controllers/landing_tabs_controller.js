import { Controller } from '@hotwired/stimulus';

const ADVANCE_INTERVAL = 6000;

export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        this.index = 0;
        this.timer = null;
        this.stopped = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        // Nothing should cycle off-screen: a reader arriving at the section
        // otherwise finds it mid-rotation on whichever panel the timer landed.
        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.start();
                } else {
                    this.pause();
                }
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

    start() {
        if (this.stopped || this.timer !== null) {
            return;
        }
        this.timer = setInterval(() => {
            this.show((this.index + 1) % this.panelTargets.length);
        }, ADVANCE_INTERVAL);
    }

    pause() {
        clearInterval(this.timer);
        this.timer = null;
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
