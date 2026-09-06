import { APIRequestContext, expect, Page } from '@playwright/test';
import { coverageScaled } from './timeouts';

const mailpitUrl =
    process.env['MAILPIT_URL'] ?? 'https://mailpit.loupe.dev.localhost';

/** Subject of the registration verification email. */
export const VERIFICATION_SUBJECT = 'Confirm your account';

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
            { timeout: coverageScaled(10000) },
        )
        .toBe(true);
    return verifyUrl;
}

/**
 * Poll Mailpit until an email arrives for the given address, then return its HTML body and subject.
 * Uses Playwright's APIRequestContext to stay consistent with the rest of the helper layer.
 *
 * Safe ONLY for per-run-unique addresses (e.g. `test+foo+${RUN}@example.com`)
 * that will ever receive a single message: it resolves on whatever is newest
 * the moment ANY message exists, and email is delivered asynchronously — for
 * a reused address, or one that receives more than one email in a test, the
 * newest message can be a stale or unrelated one. In those cases use
 * getEmailWithSubject with an afterId mark from latestEmailIdWithSubject.
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
            { timeout: coverageScaled(10000) },
        )
        .toBe(true);
    return result;
}

/**
 * The id of the newest message for this address whose subject matches
 * (case-insensitive substring, per Mailpit search), or undefined when there
 * is none. Capture it BEFORE the action that sends the awaited mail
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
 * Poll Mailpit until an email whose subject matches (case-insensitive
 * substring, per Mailpit search) arrives for the given address, then return
 * its HTML body. Unlike getLatestEmailTo, this
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
    /** Defaults to DEFAULT_DISPLAY_NAME when a spec does not care. */
    fullName?: string;
}

/**
 * Registration fills the display name from the email client-side, but every
 * helper here types it in explicitly: leaning on the Stimulus controller would
 * turn any JS regression into a wall of unrelated timeouts, since the
 * authenticated fixture registers through this same form.
 */
export const DEFAULT_DISPLAY_NAME = 'E2E User';

/**
 * Fill the registration form, poll Mailpit for the verification link, and
 * navigate to it. Returns with the browser on the first-run wizard's welcome
 * step — a brand-new account owns no project and hasn't completed (or
 * skipped) the wizard yet, so LandingController lands it there.
 */
export async function registerFreshUser(
    page: Page,
    request: APIRequestContext,
    credentials: Credentials,
): Promise<void> {
    // Email is delivered asynchronously (messenger worker) and Mailpit is
    // shared across worktrees and never cleared, so "the newest message for
    // this address" can be a STALE verification email from a previous run —
    // with another worktree's host in its link. Mark the inbox before
    // registering and wait for a message that arrived after the mark.
    const previousVerification = await latestEmailIdWithSubject(
        request,
        credentials.email,
        VERIFICATION_SUBJECT,
    );

    await page.goto('/register');
    await page.getByLabel('Email').fill(credentials.email);
    await page
        .getByLabel('Display name')
        .fill(credentials.fullName ?? DEFAULT_DISPLAY_NAME);
    await page.getByLabel('Password').fill(credentials.password);
    await page.getByLabel('I agree to').check();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL('/register/check-email');

    const received = await getEmailWithSubject(
        request,
        credentials.email,
        VERIFICATION_SUBJECT,
        30000,
        previousVerification,
    );
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
