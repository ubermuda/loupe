<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Project\Event\ProjectCreating;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class SeedBoardColumnsOnProjectCreating
{
    public function __construct(
        private BoardColumnSeeder $seeder,
    ) {
    }

    public function __invoke(ProjectCreating $event): void
    {
        $this->seeder->seed($event->project);
    }
}
