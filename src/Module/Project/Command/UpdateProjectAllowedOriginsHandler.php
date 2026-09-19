<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Project\Service\SiteOrigins;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateProjectAllowedOriginsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateProjectAllowedOriginsCommand $command): void
    {
        $origins = [];
        foreach (preg_split('/\R/', $command->origins) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $origin = SiteOrigins::normalise($line)
                ?? throw new DomainErrors(['origins' => 'project.allowed_origins.error.invalid']);
            $origins[$origin] = true;
        }

        if (\count($origins) > SiteOrigins::MAX) {
            throw new DomainErrors(['origins' => 'project.allowed_origins.error.too_many']);
        }

        $project = $command->project;
        $project->allowedOrigins = array_keys($origins);
        $this->em->flush();

        $this->auditor->record(
            'project.allowed_origins_updated',
            AuditOutcome::Success,
            ['projectId' => (string) $project->id, 'count' => \count($project->allowedOrigins)],
            new AuditSubject('project', (string) $project->id),
        );
    }
}
