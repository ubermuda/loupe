<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegenerateProjectMcpTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private Auditor $auditor,
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

        // Same row lock the mint handler takes: two concurrent regenerations
        // would each delete what they read, orphaning the loser's token. The
        // lock alone is not enough — a request that loaded the project before
        // waiting still holds the stale association, so read the committed id.
        [$raw, $tokenId, $revokedTokenId] = $this->em->wrapInTransaction(
            /** @return array{non-empty-string, string, ?string} */
            function () use ($project): array {
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

                $previousId = $this->projects->committedMcpTokenId($project);
                $previous = null !== $previousId ? $this->em->find(ApiToken::class, $previousId) : null;
                if (null !== $previous) {
                    $project->mcpToken = null;
                    $this->em->remove($previous);
                }

                // ApiToken.label is a 100-char column while Project.name allows 100 — truncate to fit.
                [$token, $raw] = ApiToken::issue($project->owner, 'MCP: '.mb_substr($project->name, 0, 95), ApiTokenScope::Mcp);
                $project->mcpToken = $token;
                $this->em->persist($token);
                $this->em->flush();

                // Not $previous->id: Doctrine nulls a deleted entity's identifier on flush.
                return [$raw, (string) $token->id, null !== $previous ? $previousId : null];
            },
        );

        // Recorded after the commit, never inside it. The audit sink drains
        // outside the business transaction on purpose, so a record written in
        // there would outlive a rollback and claim a rotation that never landed.
        $this->auditor->record(
            'project.mcp_token_regenerated',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                'tokenId' => $tokenId,
                'previousTokenId' => $revokedTokenId,
            ],
            new AuditSubject('api_token', $tokenId),
            Auditor::CATEGORY_SECURITY,
        );

        return $raw;
    }
}
