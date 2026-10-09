<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Workflow\Contract\DocumentFacts;

/** What a test says about a card. FactsMother turns it into the facts of the providers. */
final readonly class CardInputs
{
    /**
     * @param list<DocumentFacts> $documents
     * @param list<DocumentFacts> $parentDocuments
     */
    public function __construct(
        public ?string $slot,
        public string $type,
        public bool $hasOpenBlocker,
        public bool $isChild,
        public int $childCount,
        public int $openChildCount,
        public array $documents,
        public bool $childMergedIntoEpicBranch,
        public array $parentDocuments,
        public ?string $parentSlot,
    ) {
    }
}
