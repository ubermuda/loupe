<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use Uri\WhatWg\Url;

/**
 * The sites a project lets its sign-in widget run on. An origin is kept in
 * the form a browser gives it in `location.origin` and `MessageEvent.origin`,
 * so a stored value compares equal to what the browser reports.
 */
final class SiteOrigins
{
    public const int MAX = 20;

    private const string HOST = '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$|^\[[0-9a-f:.]+\]$/';

    /**
     * The origin a value names, or null when it is not one the widget can use.
     * Plain http is accepted on a loopback host only, because the widget needs
     * a secure context for PKCE.
     */
    public static function normalise(string $value): ?string
    {
        $url = Url::parse(trim($value));
        if (null === $url) {
            return null;
        }

        $scheme = $url->getScheme();
        $host = $url->getAsciiHost();
        $path = $url->getPath();
        if (!\in_array($scheme, ['https', 'http'], true)
            || null === $host
            || 1 !== preg_match(self::HOST, $host)
            || null !== $url->getUsername()
            || null !== $url->getPassword()
            || null !== $url->getQuery()
            || null !== $url->getFragment()
            || ('' !== $path && '/' !== $path)
            || ('http' === $scheme && !self::isLoopback($host))) {
            return null;
        }

        $port = $url->getPort();

        return $scheme.'://'.$host.(null === $port ? '' : ':'.$port);
    }

    private static function isLoopback(string $host): bool
    {
        return 'localhost' === $host
            || str_ends_with($host, '.localhost')
            || '[::1]' === $host
            || 1 === preg_match('/^127\.\d+\.\d+\.\d+$/', $host);
    }
}
