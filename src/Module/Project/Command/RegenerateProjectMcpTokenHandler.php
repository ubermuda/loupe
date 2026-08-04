<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RegenerateProjectMcpTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Revokes the current MCP token (if any) and issues a fresh one in its place.
     *
     * @return non-empty-string the raw token, shown to the user exactly once
     */
    public function __invoke(RegenerateProjectMcpTokenCommand $command): string
    {
        $project = $command->project;

        // Same row lock the mint handler takes, for the same reason: two
        // concurrent regenerations would each delete what they read and persist
        // their own token, leaving the loser's valid but bound to no project.
        return $this->em->wrapInTransaction(function () use ($project): string {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $previous = $project->mcpToken;
            if (null !== $previous) {
                $project->mcpToken = null;
                $this->em->remove($previous);
            }

            // ApiToken.label is a 100-char column while Project.name allows 100 — truncate to fit.
            [$token, $raw] = ApiToken::issue($project->owner, 'MCP: '.mb_substr($project->name, 0, 95), ApiTokenScope::Mcp);
            $project->mcpToken = $token;
            $this->em->persist($token);
            $this->em->flush();

            $this->logger->info('project.mcp_token_regenerated', [
                'projectId' => (string) $project->id,
                'tokenId' => (string) $token->id,
                'previousTokenId' => null !== $previous ? (string) $previous->id : null,
            ]);

            return $raw;
        });
    }
}
