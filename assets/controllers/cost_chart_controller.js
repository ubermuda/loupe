import { Controller } from '@hotwired/stimulus';

/**
 * Shows the hover card of a bar in the cost chart, on pointer or keyboard
 * focus. The server renders every card. A click follows the bar's own link.
 */
export default class extends Controller {
    static targets = ['bar', 'card'];

    show(event) {
        const index = event.params.index;
        const card = this.cardTargets[index];
        const bar = this.barTargets[index];
        if (!card || !bar) {
            return;
        }

        this.hide();
        card.hidden = false;

        const plotBox = card.parentElement.getBoundingClientRect();
        // The link spans the whole plot height, so the top comes from the painted marks.
        const marks = [...bar.querySelectorAll('path, text')];
        const barBox = bar.getBoundingClientRect();
        const top = Math.min(
            ...marks.map((mark) => mark.getBoundingClientRect().top),
            barBox.top + barBox.height,
        );
        // CSS caps the card at the plot width, so its left edge stays in [0, plot width - card width].
        const halfWidth =
            Math.min(card.getBoundingClientRect().width, plotBox.width) / 2;
        const centre = barBox.left + barBox.width / 2 - plotBox.left;
        const left = Math.min(
            Math.max(centre, halfWidth),
            plotBox.width - halfWidth,
        );

        card.style.left = `${left}px`;
        // A card taller than the room above the bar opens below the plot instead.
        const below = top - 8 - card.getBoundingClientRect().height < 0;
        card.classList.toggle('lp-cost-card--below', below);
        card.style.top = below
            ? `${barBox.top + barBox.height - plotBox.top + 8}px`
            : `${top - plotBox.top - 8}px`;
    }

    hide() {
        this.cardTargets.forEach((card) => {
            card.hidden = true;
        });
    }
}
