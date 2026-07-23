<?php

declare(strict_types=1);

namespace App\Utils;

final class SafeRedirect
{
    /**
     * Returns true only for a same-origin absolute path that a browser cannot
     * interpret as an off-site URL. Rejects protocol-relative (`//evil.com`)
     * and backslash-host (`/\evil.com`) targets, which both start with `/` but
     * navigate cross-origin.
     */
    public static function isLocalPath(string $path): bool
    {
        if (!str_starts_with($path, '/')) {
            return false;
        }

        if (str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return false;
        }

        return true;
    }
}
