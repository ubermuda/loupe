<?php

declare(strict_types=1);

namespace App\Module\SiteReview;

/**
 * The API paths the embedded widget calls.
 *
 * CORS and the write rate limit are both path-scoped, and both used to test for
 * `/api/site-review` alone. The widget now also calls a Board path, and neither
 * protection would have covered it: a listener that tests one prefix silently
 * exempts every other endpoint the widget reaches.
 *
 * Paths rather than namespaces, so this stays free of the arkitect rule fencing
 * Board as a leaf. A string is not a dependency.
 */
final class WidgetApiPaths
{
    /** @var list<string> */
    public const array PREFIXES = ['/api/site-review', '/api/board/cards'];

    public static function matches(string $path): bool
    {
        return array_any(self::PREFIXES, fn ($prefix) => str_starts_with($path, (string) $prefix));
    }
}
