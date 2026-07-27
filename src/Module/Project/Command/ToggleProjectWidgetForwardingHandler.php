<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ToggleProjectWidgetForwardingHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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

        $this->logger->info('project.widget_forwarding_toggled', [
            'projectId' => (string) $command->project->id,
            'tokenId' => (string) $token->id,
            'forwardsToAgent' => $token->forwardsToAgent,
        ]);

        return $token->forwardsToAgent;
    }
}
