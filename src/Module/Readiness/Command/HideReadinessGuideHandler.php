<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class HideReadinessGuideHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(HideReadinessGuideCommand $command): void
    {
        $project = $command->project;
        if (null !== $project->readinessGuideHiddenAt) {
            return;
        }

        $project->readinessGuideHiddenAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditor->record(
            'readiness.guide_hidden',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
