<?php

declare(strict_types=1);

namespace App\Utils;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The URL-safe handle derived from a name. No locale is passed, so the result
 * never depends on the request that happens to trigger the derivation.
 */
final class Slug
{
    public const string PATTERN = '^[a-z0-9]+(-[a-z0-9]+)*$';

    /** Returns an empty string when the name holds nothing that transliterates, such as an emoji. */
    public static function fromName(string $name): string
    {
        return new AsciiSlugger()->slug($name)->lower()->toString();
    }

    /**
     * The slug a record named $name should hold. A current slug stays when it is the
     * name's slug or that slug with a numeric suffix, which a collision backfill gives.
     */
    public static function forName(string $name, ?string $current): string
    {
        $slug = self::fromName($name);
        if (null !== $current && '' !== $slug && 1 === preg_match('/^'.preg_quote($slug, '/').'(-\d+)?$/', $current)) {
            return $current;
        }

        return $slug;
    }
}
