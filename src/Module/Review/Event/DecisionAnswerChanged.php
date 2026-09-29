<?php

declare(strict_types=1);

namespace App\Module\Review\Event;

use Symfony\Component\Uid\Uuid;

/**
 * The stored answer to one decision changed. Dispatched after the commit, with
 * the state as stored: an unanswered decision has no picks, no note and nobody.
 */
final readonly class DecisionAnswerChanged
{
    /** @param list<int> $optionIndexes indexes into the options of the latest version */
    public function __construct(
        public Uuid $projectId,
        public Uuid $documentId,
        public string $decisionId,
        public int $versionNumber,
        public array $optionIndexes,
        public ?string $note,
        public ?string $answeredByName,
        public \DateTimeImmutable $answeredAt,
    ) {
    }
}
