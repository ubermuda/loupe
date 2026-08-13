/**
 * The documents-list search box, which only a browser can exercise: it depends on
 * a 300ms debounce, on Turbo replacing the page body, and on the focused input
 * surviving that replacement. PHPUnit renders the markup but never types into it,
 * so nothing else in the suite can see a caret that has been thrown away.
 *
 * Every test seeds its own user and documents through the dev-only endpoints
 * (/dev/register-and-verify, /dev/seed/document), so nothing here touches Mailpit
 * and the tests cannot disturb each other.
 */

import { test, expect, type Page } from '@playwright/test';
import { suppressToolbar, suppressWidget } from '../fixtures';

// Guest by default — each test logs in as the user it just created.
test.use({ storageState: { cookies: [], origins: [] } });

const RUN = Date.now();

const SEARCH_BOX = 'input[name="search"]';

// Marks the input that is live *now*, so the swap that replaces it is
// observable. Nothing in the app reads it.
const STALE = 'data-e2e-stale';

/**
 * Tags the live search input. Submitting is a full Turbo visit that replaces the
 * body, so the input rendered by the response is a different element and carries
 * no tag.
 */
async function markSearchInput(page: Page): Promise<void> {
    await page.locator(SEARCH_BOX).evaluate((element, attribute) => {
        element.setAttribute(attribute, '1');
    }, STALE);
    // The tag must land on exactly one input: the waits below key on its absence,
    // so a second untagged one already in the page would satisfy them for free.
    await expect(page.locator(`${SEARCH_BOX}[${STALE}]`)).toHaveCount(1);
}

/**
 * Waits for the debounced submit to have both committed to the address bar and
 * rendered. Sleeping past the debounce instead only buys a fixed margin for a
 * dev-mode round trip that has none, and typing resumed early goes into an input
 * Turbo is about to throw away — which is the very bug these tests describe.
 */
async function searchVisitLanded(page: Page, term: string): Promise<void> {
    await expect(page).toHaveURL(
        (url) => url.searchParams.get('search') === term,
    );
    await expect(page.locator(`${SEARCH_BOX}:not([${STALE}])`)).toHaveCount(1);
}

/**
 * Register, log in and seed two documents, returning the list URL. `tag` keeps
 * each test's user and documents distinct.
 */
async function openDocumentList(page: Page, tag: string): Promise<string> {
    await suppressToolbar(page);
    await suppressWidget(page);

    const email = `e2e+docsearch+${tag}+${RUN}@example.com`;
    const password = 'E2eDocumentSearch1!';

    const registered = await page.request.post('/dev/register-and-verify', {
        form: { fullName: 'E2E Searcher', email, password },
    });
    expect(registered.status()).toBe(200);

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    // A fresh user owns no project yet, so LandingController lands on the wizard;
    // seeding the document below creates the project the wizard would have.
    await expect(page).toHaveURL('/welcome');

    const seeded = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Kafka partition rebalancing',
            markdown:
                '# Kafka partition rebalancing\n\nConsumer groups and offsets.',
        },
    });
    expect(seeded.status()).toBe(201);
    const body = await seeded.json();

    const other = await page.request.post('/dev/seed/document', {
        form: {
            title: 'Onboarding wizard copy',
            markdown: '# Onboarding wizard copy\n\nThe first-run experience.',
        },
    });
    expect(other.status()).toBe(201);

    const listUrl = `/projects/${body.projectId}/documents`;
    await page.goto(listUrl);
    await expect(page.locator(SEARCH_BOX)).toBeVisible();

    return listUrl;
}

test('typing through the debounce keeps every keystroke in the search box', async ({
    page,
}) => {
    await openDocumentList(page, 'keys');

    const search = page.locator(SEARCH_BOX);
    await search.click();

    // Type, let the search submit, then keep typing WITHOUT clicking back into
    // the field. Breaking off mid-word is ordinary typing, and it is the whole
    // bug: if the submit destroys the focused input, the second half of the word
    // lands nowhere.
    await markSearchInput(page);
    await page.keyboard.type('kafka');
    await searchVisitLanded(page, 'kafka');

    await markSearchInput(page);
    await page.keyboard.type(' partition');
    // Checked before the second visit as well as after it: waiting first would
    // report a lost keystroke as a timeout rather than naming the value kept.
    await expect(search).toHaveValue('kafka partition');

    await searchVisitLanded(page, 'kafka partition');
    await expect(search).toHaveValue('kafka partition');
});

test('the caret stays at the end, so continued typing is not reordered', async ({
    page,
}) => {
    await openDocumentList(page, 'caret');

    const search = page.locator(SEARCH_BOX);
    await search.click();

    // Restoring focus but resetting the caret to position 0 would turn lost
    // keystrokes into re-ordered ones — "onkafka" rather than "kafkaon" — which
    // looks like the app working and is worse. Asserted separately from the
    // value test above because the two fail for different reasons.
    await markSearchInput(page);
    await page.keyboard.type('kafka');
    await searchVisitLanded(page, 'kafka');

    await markSearchInput(page);
    await page.keyboard.type('on');
    await expect(search).toHaveValue('kafkaon');

    await searchVisitLanded(page, 'kafkaon');
    await expect(search).toHaveValue('kafkaon');
    await expect(search).toBeFocused();
});

test('a debounced search filters the list and stays linkable in the URL', async ({
    page,
}) => {
    const listUrl = await openDocumentList(page, 'url');

    await page.locator(SEARCH_BOX).click();
    await page.keyboard.type('kafka');

    // The address bar has to carry the query, or a search cannot be shared or
    // survive a reload — that is what a full navigation buys, and a fix that
    // quietly dropped it would be a regression traded for a fix.
    await expect(page).toHaveURL(new RegExp(`${listUrl}\\?.*search=kafka`));

    await expect(page.getByText('Kafka partition rebalancing')).toBeVisible();
    await expect(page.getByText('Onboarding wizard copy')).toHaveCount(0);

    // Reload proves the URL alone reproduces the filtered view.
    await page.reload();
    await expect(page.locator(SEARCH_BOX)).toHaveValue('kafka');
    await expect(page.getByText('Onboarding wizard copy')).toHaveCount(0);
});
