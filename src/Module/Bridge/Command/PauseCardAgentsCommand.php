<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class PauseCardAgentsCommand
{
    public function __construct(
        public Project $project,
        public Uuid $cardId,
        public User $requestedBy,
    ) {
    }
}
