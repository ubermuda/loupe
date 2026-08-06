<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RevokeApiTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RevokeApiTokenCommand $command): void
    {
        $token = $command->token;

        // Idempotent: a stale double-submit of the revoke form must not re-stamp
        // revokedAt or emit a second log entry.
        if (null !== $token->revokedAt) {
            return;
        }

        $token->revoke();

        // Revocation keeps the row, so the ON DELETE SET NULL cascade on
        // Project.widgetToken/mcpToken never fires; clear the binding by hand.
        // Branch on which query matched rather than on object identity, which
        // stays correct even if the association and $token load as distinct
        // instances.
        $widgetProject = $this->projects->findOneByWidgetToken($token);
        if (null !== $widgetProject) {
            $widgetProject->widgetToken = null;
        } else {
            $mcpProject = $this->projects->findOneByMcpToken($token);
            if (null !== $mcpProject) {
                $mcpProject->mcpToken = null;
            }
        }

        $this->em->flush();

        $this->logger->info('account.api_token.revoked', [
            'userId' => null !== $token->owner->id ? (string) $token->owner->id : null,
            'tokenId' => null !== $token->id ? (string) $token->id : null,
            'label' => $token->label,
        ]);
    }
}
