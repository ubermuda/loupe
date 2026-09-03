<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
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
        $token = $command->project->widgetToken;
        if (null === $token) {
            throw new DomainErrors(['forwarding' => 'project.error.widget_token_missing']);
        }

        $token->forwardsToAgent = !$token->forwardsToAgent;
        $this->em->flush();

        $this->auditor->record(
            'project.widget_forwarding_toggled',
            AuditOutcome::Success,
            [
                'projectId' => (string) $command->project->id,
                'tokenId' => (string) $token->id,
                'forwardsToAgent' => $token->forwardsToAgent,
            ],
            new AuditSubject('api_token', (string) $token->id),
        );

        return $token->forwardsToAgent;
    }
}
