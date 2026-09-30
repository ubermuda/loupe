<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;

/**
 * Which column an approved document, or a pull request with green checks,
 * moves its card out of, and into.
 *
 * The mapping is hard-coded until a second consumer asks for it to be
 * configurable. It reads the document's tags rather than its title, because a
 * title is prose a person edits and a tag is a value they pick.
 */
final readonly class LifecycleStages
{
    /** @var list<array{tags: list<string>, from: string, to: string}> */
    private const array STAGES = [
        ['tags' => ['product'], 'from' => 'product-design', 'to' => 'tech-design'],
        ['tags' => ['design', 'decisions'], 'from' => 'tech-design', 'to' => 'implementation'],
    ];

    /** @return array{from: string, to: string} */
    public function forPassedChecks(): array
    {
        return ['from' => 'implementation', 'to' => 'in-review'];
    }

    /**
     * The move an approval of this document means, or null when its tags name
     * no stage.
     *
     * @return array{from: string, to: string}|null
     */
    public function forDocument(Document $document): ?array
    {
        return $this->forTagNames(array_values(array_map(
            static fn (Tag $tag): string => $tag->name,
            $document->tags->toArray(),
        )));
    }

    /**
     * The move an approval of a document with these tag names means. A
     * document carrying the tags of both stages is ambiguous, so it moves
     * nothing.
     *
     * @param list<string> $names
     *
     * @return array{from: string, to: string}|null
     */
    public function forTagNames(array $names): ?array
    {
        $names = array_map(mb_strtolower(...), $names);

        $matches = [];
        foreach (self::STAGES as $stage) {
            $wanted = array_diff($stage['tags'], $names);
            if ([] === $wanted) {
                $matches[] = ['from' => $stage['from'], 'to' => $stage['to']];
            }
        }

        return 1 === \count($matches) ? $matches[0] : null;
    }
}
