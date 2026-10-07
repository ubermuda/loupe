<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

final readonly class CardFacts
{
    /**
     * @param ?string             $slot                      a slot key, '@backlog', '@terminal', or null for any other column
     * @param list<DocumentFacts> $documents
     * @param bool                $childMergedIntoEpicBranch whether a child pull request merged into the epic branch of this card
     */
    public function __construct(
        public ?string $slot,
        public string $type,
        public bool $hasOpenBlocker,
        public bool $isChild,
        public int $childCount,
        public int $openChildCount,
        public array $documents,
        public bool $childMergedIntoEpicBranch = false,
    ) {
    }
}
