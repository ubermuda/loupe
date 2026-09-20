<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use Uri\WhatWg\Url;

/**
 * The sites a project lets its sign-in widget run on. An origin is kept in
 * the form a browser gives it in `location.origin` and `MessageEvent.origin`,
 * so a stored value compares equal to what the browser reports.
 *
 * An entry can also name one wildcard label, as in https://*.example.com,
 * which every git worktree needs: each one serves the app on its own host.
 */
final class SiteOrigins
{
    public const int MAX = 20;

    private const string WILDCARD = '*.';

    private const string HOST = '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$|^\[[0-9a-f:.]+\]$/';

    /**
     * Public suffixes of more than one label, which a wildcard must not cover.
     * The full list is far longer, so this catches the common mistake rather
     * than every one. A single-label remainder is refused outright.
     *
     * @var list<string>
     */
    private const array MULTI_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'gov.uk', 'ac.uk', 'net.uk', 'sch.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz', 'ac.nz',
        'co.jp', 'ne.jp', 'or.jp', 'go.jp', 'ac.jp',
        'com.br', 'net.br', 'org.br', 'gov.br',
        'co.za', 'org.za', 'net.za', 'gov.za',
        'com.cn', 'net.cn', 'org.cn', 'gov.cn',
        'co.in', 'net.in', 'org.in', 'gov.in',
        'com.mx', 'com.ar', 'com.tr', 'com.sg', 'com.hk', 'com.tw', 'com.pl',
        'co.il', 'co.kr', 'co.id', 'co.th',
    ];

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

    /**
     * One entry of a project's list: an origin, or an origin whose first host
     * label is a single `*`. The wildcard needs a remainder of two labels or
     * more that is not a public suffix, so one entry cannot cover a whole
     * registry.
     */
    public static function normalisePattern(string $value): ?string
    {
        $value = trim($value);
        $withoutScheme = (string) preg_replace('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', '', $value);
        if (!str_starts_with($withoutScheme, self::WILDCARD)) {
            return self::normalise($value);
        }

        $standIn = 'wildcard-label.';
        $origin = self::normalise(substr_replace($value, $standIn, \strlen($value) - \strlen($withoutScheme), \strlen(self::WILDCARD)));
        if (null === $origin) {
            return null;
        }

        $rest = explode('.', self::hostOf($origin), 2)[1] ?? '';
        if (substr_count($rest, '.') < 1 || \in_array($rest, self::MULTI_LABEL_SUFFIXES, true)) {
            return null;
        }

        return str_replace($standIn, self::WILDCARD, $origin);
    }

    /** Whether an origin the browser reported is the one this entry names. */
    public static function matches(string $pattern, string $origin): bool
    {
        $origin = self::normalise($origin);
        if (null === $origin) {
            return false;
        }

        if (!str_contains($pattern, '://'.self::WILDCARD)) {
            return $pattern === $origin;
        }

        [$patternScheme, $patternRest] = explode('://', $pattern, 2);
        [$originScheme, $originRest] = explode('://', $origin, 2);
        if ($patternScheme !== $originScheme) {
            return false;
        }

        $label = strstr($originRest, '.', before_needle: true);

        return false !== $label && '' !== $label && substr($patternRest, \strlen(self::WILDCARD)) === substr($originRest, \strlen($label) + 1);
    }

    /**
     * Whether any entry of a project's list allows this origin.
     *
     * @param list<string> $patterns
     */
    public static function allows(array $patterns, string $origin): bool
    {
        return array_any($patterns, static fn (string $pattern): bool => self::matches($pattern, $origin));
    }

    private static function hostOf(string $origin): string
    {
        $rest = explode('://', $origin, 2)[1] ?? '';

        return explode(':', $rest, 2)[0];
    }

    private static function isLoopback(string $host): bool
    {
        return 'localhost' === $host
            || str_ends_with($host, '.localhost')
            || '[::1]' === $host
            || 1 === preg_match('/^127\.\d+\.\d+\.\d+$/', $host);
    }
}
