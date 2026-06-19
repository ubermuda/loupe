<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;

final readonly class AddCommentCommand
{
    public function __construct(
        public User $actor,
        public Document $document,
        public int $start,
        public int $length,
        public string $body,
    ) {
    }
}
