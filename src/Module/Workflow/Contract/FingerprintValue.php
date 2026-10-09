<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Helpers for a fact provider that fingerprints a list, so the order of the list never counts. */
final class FingerprintValue
{
    /**
     * Sorts by the encoded form, so a list of lists sorts the same way as a list of strings.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public static function sorted(array $items): array
    {
        usort($items, static fn (mixed $a, mixed $b): int => json_encode($a, \JSON_THROW_ON_ERROR) <=> json_encode($b, \JSON_THROW_ON_ERROR));

        return $items;
    }

    /**
     * @param list<DocumentFacts> $documents
     *
     * @return list<array{string, list<string>}>
     */
    public static function documents(array $documents): array
    {
        return self::sorted(array_map(static fn (DocumentFacts $document): array => [$document->status, self::sorted($document->tags)], $documents));
    }
}
