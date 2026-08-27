<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;

final readonly class UndoVerdictCommand
{
    public function __construct(
        public Document $document,
        // Who withdrew it. The withdrawal is a row in the verdict log like any
        // other, and a log entry with no author answers half the question.
        public User $actor,
    ) {
    }
}
