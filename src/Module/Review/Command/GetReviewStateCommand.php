<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class GetReviewStateCommand
{
    public function __construct(
        public Uuid $documentId,
        public User $owner,
    ) {
    }
}
