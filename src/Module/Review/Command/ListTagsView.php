<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Tag;

/**
 * The whole tag vocabulary of one project, with how widely each name is used.
 *
 * @phpstan-type TagCount array{tag: Tag, documentCount: int}
 */
final readonly class ListTagsView
{
    /** @param list<TagCount> $tags */
    public function __construct(
        public array $tags,
    ) {
    }
}
