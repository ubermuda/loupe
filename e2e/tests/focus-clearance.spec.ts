import { test, expect } from '@playwright/test';
import { expectFilterFocusRingVisible } from './helpers';

test('focus clearance preserves fractional bounds and rejects unsafe assumptions', async ({
    page,
}) => {
    await page.setContent(`
        <div id="filters" style="width: 200.140625px; padding: 2px; overflow: auto; scrollbar-width: none;">
            <input aria-label="Search" style="box-sizing: border-box; width: 100%; height: 36px; display: block; box-shadow: 0 0 0 2px black;">
        </div>
    `);
    const filters = page.locator('#filters');
    const field = page.getByRole('textbox', { name: 'Search' });
    await field.focus();
    await expectFilterFocusRingVisible(field, filters);

    await filters.evaluate((element) => {
        element.style.padding = '0px';
    });
    await expect(expectFilterFocusRingVisible(field, filters)).rejects.toThrow(
        /toBeGreaterThanOrEqual/,
    );
    await filters.evaluate((element) => {
        element.style.padding = '2px';
        element.style.border = '1px solid black';
    });
    await expect(expectFilterFocusRingVisible(field, filters)).rejects.toThrow(
        /borders/,
    );
    await filters.evaluate((element) => {
        element.style.border = '0px';
        element.style.scrollbarWidth = 'auto';
    });
    await expect(expectFilterFocusRingVisible(field, filters)).rejects.toThrow(
        /scrollbarWidth/,
    );
    await filters.evaluate((element) => {
        element.style.scrollbarWidth = 'none';
    });
    await expectFilterFocusRingVisible(field, filters);
});
