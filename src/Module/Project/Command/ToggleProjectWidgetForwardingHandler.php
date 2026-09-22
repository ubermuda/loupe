<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ToggleProjectWidgetForwardingHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return bool the state forwarding was left in
     */
    public function __invoke(ToggleProjectWidgetForwardingCommand $command): bool
    {
        $command->project->forwardsToAgent = !$command->project->forwardsToAgent;
        $this->em->flush();

        $this->auditor->record(
            'project.widget_forwarding_toggled',
            AuditOutcome::Success,
            [
                'projectId' => (string) $command->project->id,
                'forwardsToAgent' => $command->project->forwardsToAgent,
            ],
            new AuditSubject('project', (string) $command->project->id),
        );

        return $command->project->forwardsToAgent;
    }
}
