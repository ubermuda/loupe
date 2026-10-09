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

/** The Comments panel row of the thread whose row shows this text. */
export function commentRow(page: Page, text: string) {
    return page.locator('#comment-rows .lp-comment-row').filter({
        hasText: text,
    });
}

/** The thread card a row opens. It resolves again after a stream swaps the card. */
export async function threadOf(page: Page, text: string) {
    const id = await commentRow(page, text).getAttribute('aria-controls');

    return page.locator(`#${id}`);
}

/** Opens the thread of a row as a popover, and returns the card. */
export async function openThread(page: Page, text: string) {
    await showPanel(page, 'Comments');
    const row = commentRow(page, text);
    const thread = await threadOf(page, text);
    await row.click();
    await expect(thread).toBeVisible();
    await expect(row).toHaveAttribute('aria-expanded', 'true');

    return thread;
}
