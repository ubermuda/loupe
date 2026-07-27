<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;

final readonly class SubmitReviewCommand
{
    public function __construct(
        public User $reviewer,
        public Document $document,
        // Raw submitted value — parsed into a Verdict by the handler, which
        // throws DomainErrors on an unrecognised value.
        public string $verdict,
    ) {
    }
}
