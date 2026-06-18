<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Account\Entity\User;

final readonly class CreateDocumentCommand
{
    public function __construct(
        public User $owner,
        public string $title,
        public string $markdown,
    ) {
    }
}
