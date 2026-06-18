import { APIRequestContext, expect } from '@playwright/test';

const mailpitUrl =
    process.env['MAILPIT_URL'] ??
    'https://mailpit.symfony-skeleton.dev.localhost';

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
