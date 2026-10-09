import { Controller } from '@hotwired/stimulus';

const MINUTE = 60;

const readTexts = () => {
    const source = document.querySelector('script[data-state-age-texts]');
    if (!source) {
        return null;
    }
    try {
        return JSON.parse(source.textContent);
    } catch {
        return null;
    }
};

export const ageForm = (seconds) => {
    const minutes = Math.floor(Math.max(0, seconds) / MINUTE);
    const [unit, count] =
        minutes < 60
            ? ['minute', minutes]
            : minutes < 1440
              ? ['hour', Math.floor(minutes / 60)]
              : ['day', Math.floor(minutes / 1440)];
    if (count === 0) {
        return ['now', 0];
    }

    return [count === 1 ? unit : `${unit}s`, count];
};

/**
 * Keeps the age of a card state current while the page stays open. It counts
 * from the server clock, so a wrong clock on the reader's machine moves nothing.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { since: Number, now: Number, mode: String };

    connect() {
        this.offset = this.nowValue - Date.now() / 1000;
        this.timer = setInterval(() => this.render(), MINUTE * 1000);
        this.render();
    }

    disconnect() {
        clearInterval(this.timer);
    }

    render() {
        const texts = readTexts()?.[this.modeValue];
        if (!texts) {
            return;
        }
        const [form, count] = ageForm(
            Date.now() / 1000 + this.offset - this.sinceValue,
        );
        if (typeof texts[form] === 'string') {
            this.element.textContent = texts[form].replace(
                '%count%',
                String(count),
            );
        }
    }
}
