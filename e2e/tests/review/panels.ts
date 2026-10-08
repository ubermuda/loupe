import { expect, type Page } from '@playwright/test';

export const PANELS_KEY = 'loupe.review.panels';

export type PanelName = 'Decisions' | 'Comments' | 'Outline';

/** A toolbar button of the review page, by its label. */
export function panelButton(page: Page, name: PanelName) {
    return page
        .locator('.lp-review-toolbar')
        .getByRole('button', { name, exact: true });
}

/** Switches a review panel on, unless it is on already. */
export async function showPanel(page: Page, name: PanelName): Promise<void> {
    const button = panelButton(page, name);
    if ((await button.getAttribute('aria-pressed')) !== 'true') {
        await button.click();
    }
    await expect(button).toHaveAttribute('aria-pressed', 'true');
}

/**
 * Stores Comments as on before every page load, so a spec that reads threads
 * finds them without a click. Decisions stays on, as by default.
 */
export async function startWithComments(page: Page): Promise<void> {
    await page.addInitScript((key: string) => {
        window.localStorage.setItem(
            key,
            JSON.stringify(['decisions', 'comments']),
        );
    }, PANELS_KEY);
}
