<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Project\Entity\Project;

final readonly class RemoveForgeRepositoryCommand
{
    public function __construct(
        public Project $project,
        public ForgeRepository $repository,
    ) {
    }
}
