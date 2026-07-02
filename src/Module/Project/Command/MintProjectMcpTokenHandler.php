<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class MintProjectMcpTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return non-empty-string the raw token, shown to the user exactly once
     */
    public function __invoke(MintProjectMcpTokenCommand $command): string
    {
        $project = $command->project;
        if (null !== $project->mcpToken) {
            $this->logger->info('project.mcp_token_mint_rejected', [
                'projectId' => (string) $project->id,
            ]);
            throw new DomainErrors(['token' => 'project.error.mcp_token_already_minted']);
        }

        // ApiToken.label is a 100-char column while Project.name allows 100 — truncate to fit.
        [$token, $raw] = ApiToken::issue($project->owner, 'MCP: '.mb_substr($project->name, 0, 95), ApiTokenScope::Mcp);
        $project->mcpToken = $token;
        $this->em->persist($token);
        $this->em->flush();

        $this->logger->info('project.mcp_token_minted', [
            'projectId' => (string) $project->id,
            'tokenId' => (string) $token->id,
        ]);

        return $raw;
    }
}
