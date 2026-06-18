<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;

final readonly class SubmitReviewCommand
{
    public function __construct(
        public User $reviewer,
        public Document $document,
        public Verdict $verdict,
    ) {
    }
}
