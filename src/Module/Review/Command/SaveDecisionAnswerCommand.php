<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;

final readonly class SaveDecisionAnswerCommand
{
    /**
     * @param list<int> $optionIndexes indexes into the options of the displayed version
     */
    public function __construct(
        public Document $document,
        public string $decisionId,
        public int $displayedVersionNumber,
        public array $optionIndexes,
        public ?string $note,
        public bool $clear,
        public User $answeredBy,
    ) {
    }
}
