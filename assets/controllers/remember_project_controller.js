import { Controller } from '@hotwired/stimulus';

const THIRTY_DAYS = 60 * 60 * 24 * 30;

/**
 * Records the project a page shows once the page renders. Turbo's hover
 * prefetch fetches a page without rendering it, so a hover never counts, and a
 * click served from the prefetch cache still does.
 */
export default class extends Controller {
    static values = { id: String };

    connect() {
        const secure = window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = `loupe_project=${encodeURIComponent(this.idValue)}; path=/; max-age=${THIRTY_DAYS}; SameSite=Lax${secure}`;
    }
}
