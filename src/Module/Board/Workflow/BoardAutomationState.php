<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\BoardSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(BoardSettings::class)]
final readonly class BoardAutomationState implements BoardSettings
{
    public function __construct(
        private BoardAutomation $automation,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function automationEnabled(Uuid $projectId): bool
    {
        $project = $this->em->getReference(Project::class, $projectId) ?? throw new \LogicException('The project is stored.');

        return $this->automation->settingsOf($project)->enabled;
    }
}
