<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/**
 * Cleans the failed check names a forge gives, so the bridge accepts them. The
 * bridge refuses the whole event on one bad name.
 */
final class FailedCheckNames
{
    public const int MAX_NAMES = 100;

    public const int MAX_LENGTH = 200;

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function clean(array $names): array
    {
        $cleaned = [];
        foreach ($names as $name) {
            $name = preg_replace('/[\p{Cc}{}"\\\\]/u', '', mb_scrub($name, 'UTF-8')) ?? '';
            $name = mb_substr(trim($name), 0, self::MAX_LENGTH);
            if ('' === $name) {
                continue;
            }
            $cleaned[] = $name;
            if (self::MAX_NAMES === \count($cleaned)) {
                break;
            }
        }

        return $cleaned;
    }

    private function __construct()
    {
    }
}
