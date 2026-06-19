<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Account\Entity\User;

final readonly class SubmitBatchCommand
{
    /** @param list<array{body: string, selector: string, text: string, url: string}> $comments */
    public function __construct(
        public User $actor,
        public array $comments,
    ) {
    }
}
