import { APIRequestContext, expect, Page } from '@playwright/test';

const mailpitUrl =
    process.env['MAILPIT_URL'] ?? 'https://mailpit.loupe.dev.localhost';

export async function fetchVerificationUrl(
    request: APIRequestContext,
    email: string,
): Promise<string> {
    let verifyUrl = '';
    await expect
        .poll(
            async () => {
                const listRes = await request.get(
                    `${mailpitUrl}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}`,
                );
                const list = await listRes.json();

                const unread = (list.messages ?? []).filter(
                    (m: { Read: boolean }) => !m.Read,
                );
                if (!unread.length) return false;

                const msgRes = await request.get(
                    `${mailpitUrl}/api/v1/message/${unread[0].ID}`,
                );
                const msg = await msgRes.json();
                const match = (msg.Text as string).match(/https?:\/\/\S+/);
                if (!match) return false;

                verifyUrl = match[0];
                return true;
            },
            { timeout: 10000 },
        )
        .toBe(true);
    return verifyUrl;
}

/**
 * Poll Mailpit until an email arrives for the given address, then return its HTML body and subject.
 * Uses Playwright's APIRequestContext to stay consistent with the rest of the helper layer.
 */
export async function getLatestEmailTo(
    request: APIRequestContext,
    address: string,
): Promise<{ body: string; subject: string }> {
    let result = { body: '', subject: '' };
    await expect
        .poll(
            async () => {
                const listRes = await request.get(
                    `${mailpitUrl}/api/v1/search?query=${encodeURIComponent(`to:${address}`)}`,
                );
                const list = await listRes.json();
                const messages: Array<{
                    ID: string;
                    Subject: string;
                    Read: boolean;
                }> = list.messages ?? [];
                if (!messages.length) return false;

                const msgRes = await request.get(
                    `${mailpitUrl}/api/v1/message/${messages[0].ID}`,
                );
                const msg = await msgRes.json();

                result = {
                    body:
                        (msg.HTML as string | undefined) ??
                        (msg.Text as string | undefined) ??
                        '',
                    subject: messages[0].Subject,
                };
                return true;
            },
            { timeout: 10000 },
        )
        .toBe(true);
    return result;
}

/**
 * The id of the newest message with this subject for this address, or undefined
 * when there is none. Capture it BEFORE the action that sends the awaited mail
 * and hand it to getEmailWithSubject as `afterId`: Mailpit is shared by every
 * worktree and is never cleared, so an address routinely already holds
 * same-subject mail from another branch's run, whose links point at that
 * branch's host. Marking the inbox first is what makes "the mail my action
 * sent" distinguishable from "mail that was already there".
 */
export async function latestEmailIdWithSubject(
    request: APIRequestContext,
    address: string,
    subject: string,
): Promise<string | undefined> {
    const listRes = await request.get(
        `${mailpitUrl}/api/v1/search?query=${encodeURIComponent(`to:${address} subject:"${subject}"`)}`,
    );
    const list = await listRes.json();
    const messages: Array<{ ID: string }> = list.messages ?? [];

    return messages[0]?.ID;
}

/**
 * Poll Mailpit until an email with the given exact subject arrives for the
 * given address, then return its HTML body. Unlike getLatestEmailTo, this
 * waits for a MATCHING message rather than resolving on whatever is newest
 * at the first check — required whenever the address may already hold other
 * mail (e.g. the fixture's own verification email sent moments earlier),
 * where "latest so far" can be stale and getLatestEmailTo would return it
 * before the awaited email has actually arrived.
 *
 * Pass `afterId` (from latestEmailIdWithSubject, captured before the action)
 * whenever a previous run may have left mail with the SAME subject: subject
 * alone cannot tell those apart, and resolving on one of them reads a link
 * belonging to another branch's app. Comparing message ids rather than
 * timestamps keeps this free of any clock skew between host and containers.
 */
export async function getEmailWithSubject(
    request: APIRequestContext,
    address: string,
    subject: string,
    timeoutMs = 30000,
    afterId?: string,
): Promise<{ body: string }> {
    let result = { body: '' };
    await expect
        .poll(
            async () => {
                const listRes = await request.get(
                    `${mailpitUrl}/api/v1/search?query=${encodeURIComponent(`to:${address} subject:"${subject}"`)}`,
                );
                const list = await listRes.json();
                const messages: Array<{ ID: string }> = list.messages ?? [];
                if (!messages.length) return false;
                // Mailpit lists newest first, so an unchanged head means the
                // awaited message has not landed yet.
                if (afterId !== undefined && messages[0].ID === afterId)
                    return false;

                const msgRes = await request.get(
                    `${mailpitUrl}/api/v1/message/${messages[0].ID}`,
                );
                const msg = await msgRes.json();

                result = {
                    body:
                        (msg.HTML as string | undefined) ??
                        (msg.Text as string | undefined) ??
                        '',
                };
                return true;
            },
            { timeout: timeoutMs },
        )
        .toBe(true);
    return result;
}

/**
 * Count the messages Mailpit currently holds for the given recipient.
 * Search-scoped by address so parallel spec files cannot race each other.
 */
export async function countEmailsTo(
    request: APIRequestContext,
    address: string,
): Promise<number> {
    const listRes = await request.get(
        `${mailpitUrl}/api/v1/search?query=${encodeURIComponent(`to:${address}`)}`,
    );
    const list = await listRes.json();
    return (list.messages ?? []).length;
}

/**
 * Delete all messages in Mailpit so each test starts with a clean inbox.
 */
export async function clearMailpit(request: APIRequestContext): Promise<void> {
    await request.delete(`${mailpitUrl}/api/v1/messages`);
}

/**
 * Extract the first URL matching the given pattern from an email body.
 * Decodes HTML entities in the result so URLs with &amp; query-param separators
 * (produced by Twig's auto-escaping) are returned as valid, navigable URLs.
 * Throws if nothing matches.
 */
export function extractLink(body: string, pattern: RegExp): string {
    const match = body.match(pattern);
    if (!match)
        throw new Error(`No link matching ${pattern} found in email body`);
    return match[0].replace(/&amp;/g, '&');
}

/**
 * Log out via the sidebar's CSRF-protected logout form.
 * GET /logout is rejected now that logout CSRF is enabled.
 */
export async function logout(page: Page): Promise<void> {
    await page.goto('/projects');
    await page.getByRole('button', { name: 'Log out' }).click();
    await expect(page).toHaveURL('/login');
}

export interface Credentials {
    email: string;
    password: string;
    name?: string;
    username?: string;
}

function usernameFromEmail(email: string): string {
    return email
        .split('@')[0]
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '')
        .slice(0, 30);
}

/**
 * Fill the registration form, poll Mailpit for the verification link, and
 * navigate to it. Returns with the browser on the first-run wizard's welcome
 * step — a brand-new account owns no project and hasn't completed (or
 * skipped) the wizard yet, so HomeController lands it there.
 */
export async function registerFreshUser(
    page: Page,
    request: APIRequestContext,
    credentials: Credentials,
): Promise<void> {
    await page.goto('/register');
    await page.getByLabel('Full name').fill(credentials.name ?? 'E2E User');
    await page
        .getByLabel('Username')
        .fill(credentials.username ?? usernameFromEmail(credentials.email));
    await page.getByLabel('Email').fill(credentials.email);
    await page.getByLabel('Password').fill(credentials.password);
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/register/check-email');

    const received = await getLatestEmailTo(request, credentials.email);
    const link = extractLink(
        received.body,
        /https?:\/\/[^\s"<]+\/register\/verify[^\s"<]*/,
    );
    await page.goto(link);
    await expect(page).toHaveURL('/welcome');
}

/**
 * Registers and verifies a fresh user (see registerFreshUser), then skips the
 * first-run wizard so callers keep this helper's long-documented postcondition:
 * "logged in, ready to use the app" — landing on /projects, exactly as before
 * the wizard existed. Specs that specifically want to exercise the wizard
 * itself should call registerFreshUser directly instead.
 */
export async function registerAndVerify(
    page: Page,
    request: APIRequestContext,
    credentials: Credentials,
): Promise<void> {
    await registerFreshUser(page, request, credentials);

    if (page.url().includes('/welcome')) {
        await page.getByRole('button', { name: 'Skip setup' }).click();
        await expect(page).toHaveURL('/projects');
    }
}
