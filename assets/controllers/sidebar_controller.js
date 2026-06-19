import { Controller } from '@hotwired/stimulus';

/**
 * Manual collapse for the app sidebar. Toggles `bp-app--collapsed` on the
 * controller root and persists the choice to localStorage. Never auto-collapses.
 *
 * Usage:
 *   <div data-controller="sidebar" class="bp-app">
 *     ...
 *     <button data-action="click->sidebar#toggle">…</button>
 *   </div>
 */
const STORAGE_KEY = 'bp.sidebar.collapsed';

export default class extends Controller {
    connect() {
        if (localStorage.getItem(STORAGE_KEY) === '1') {
            this.element.classList.add('bp-app--collapsed');
        }
    }

    toggle() {
        const collapsed = this.element.classList.toggle('bp-app--collapsed');
        localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    }
}
