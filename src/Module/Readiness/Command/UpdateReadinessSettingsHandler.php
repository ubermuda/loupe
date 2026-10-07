<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateReadinessSettingsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateReadinessSettingsCommand $command): void
    {
        $project = $command->project;
        if ($command->showGuide) {
            $project->readinessGuideHiddenAt = null;
        } elseif (null === $project->readinessGuideHiddenAt) {
            $project->readinessGuideHiddenAt = new \DateTimeImmutable();
        }
        $this->em->flush();

        $this->auditor->record(
            'readiness.settings_saved',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'showGuide' => $command->showGuide],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
