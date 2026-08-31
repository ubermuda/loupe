<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MintProjectMcpTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return non-empty-string the raw token, shown to the user exactly once
     */
    public function __invoke(MintProjectMcpTokenCommand $command): string
    {
        $project = $command->project;

        // Serialize concurrent mints for the same project with a row lock: two
        // requests that both passed the check could otherwise each persist a
        // token, leaving the losing one valid but bound to no project. Re-check
        // against committed state (not the possibly-stale in-memory
        // association) once the lock is held.
        [$raw, $tokenId] = $this->em->wrapInTransaction(
            /** @return array{non-empty-string, string} */
            function () use ($project): array {
                $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

                if ($this->projects->hasCommittedMcpToken($project)) {
                    // Recorded inside the transaction on purpose. The refusal
                    // changes no state, so the rollback this throw causes has
                    // nothing to contradict, and the record stays true.
                    $this->auditor->record(
                        'project.mcp_token_mint_rejected',
                        AuditOutcome::Refused,
                        ['projectId' => (string) $project->id],
                        new AuditSubject('project', (string) $project->id),
                        Auditor::CATEGORY_SECURITY,
                    );
                    throw new DomainErrors(['token' => 'project.error.mcp_token_already_minted']);
                }

                // ApiToken.label is a 100-char column while Project.name allows 100 — truncate to fit.
                [$token, $raw] = ApiToken::issue($project->owner, 'MCP: '.mb_substr($project->name, 0, 95), ApiTokenScope::Mcp);
                $project->mcpToken = $token;
                $this->em->persist($token);
                $this->em->flush();

                return [$raw, (string) $token->id];
            },
        );

        // Recorded after the commit, never inside it. The audit sink drains
        // outside the business transaction on purpose, so a record written in
        // there would outlive a rollback and claim a token nobody can use.
        $this->auditor->record(
            'project.mcp_token_minted',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                'tokenId' => $tokenId,
            ],
            new AuditSubject('api_token', $tokenId),
            Auditor::CATEGORY_SECURITY,
        );

        return $raw;
    }
}
