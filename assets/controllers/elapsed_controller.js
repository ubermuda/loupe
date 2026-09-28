import { Controller } from '@hotwired/stimulus';

export const TICK_MILLISECONDS = 30_000;

/**
 * Keeps the elapsed time of a `<time>` element current. The server renders
 * the first value with the same rule, so keep the two in step.
 */
export default class extends Controller {
    static values = { minutes: String, hours: String, days: String };

    connect() {
        this.render();
        this.interval = setInterval(() => this.render(), TICK_MILLISECONDS);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    render() {
        const since = Date.parse(this.element.getAttribute('datetime') ?? '');
        if (Number.isNaN(since)) {
            return;
        }
        const seconds = Math.max(0, (Date.now() - since) / 1000);
        const minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            this.show(this.minutesValue, minutes);
        } else if (minutes < 1440) {
            this.show(this.hoursValue, Math.floor(minutes / 60));
        } else {
            this.show(this.daysValue, Math.floor(minutes / 1440));
        }
    }

    show(format, count) {
        this.element.textContent = format.replace('%count%', String(count));
    }
}
