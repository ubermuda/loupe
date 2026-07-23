import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        this.timeout = setTimeout(() => this.#dismiss(), 5000);
    }

    disconnect() {
        clearTimeout(this.timeout);
    }

    dismiss() {
        this.#dismiss();
    }

    #dismiss() {
        const anim = this.element.animate(
            [
                { opacity: 1, transform: 'translateY(0)' },
                { opacity: 0, transform: 'translateY(-10px)' },
            ],
            { duration: 180, easing: 'ease-in', fill: 'forwards' },
        );
        anim.finished.then(() => this.element.remove()).catch(() => {});
    }
}
