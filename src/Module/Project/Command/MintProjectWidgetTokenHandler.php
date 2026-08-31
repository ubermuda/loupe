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

final readonly class MintProjectWidgetTokenHandler
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
    public function __invoke(MintProjectWidgetTokenCommand $command): string
    {
        $project = $command->project;

        // A row lock serialises concurrent mints: two requests past the check
        // would each persist a token, leaving the loser valid but bound to no
        // project. Re-check against committed state once held, not the
        // possibly-stale in-memory association.
        return $this->em->wrapInTransaction(function () use ($project): string {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            if ($this->projects->hasCommittedWidgetToken($project)) {
                $this->auditor->record(
                    'project.widget_token_mint_rejected',
                    AuditOutcome::Refused,
                    ['projectId' => (string) $project->id],
                    new AuditSubject('project', (string) $project->id),
                    Auditor::CATEGORY_SECURITY,
                );
                throw new DomainErrors(['token' => 'project.error.widget_token_already_minted']);
            }

            // ApiToken.label is a 100-char column while Project.name allows 100 — truncate to fit.
            [$token, $raw] = ApiToken::issue($project->owner, 'Widget: '.mb_substr($project->name, 0, 92), ApiTokenScope::SiteReview);
            $project->widgetToken = $token;
            $this->em->persist($token);
            $this->em->flush();

            $this->auditor->record(
                'project.widget_token_minted',
                AuditOutcome::Success,
                [
                    'projectId' => (string) $project->id,
                    'tokenId' => (string) $token->id,
                ],
                new AuditSubject('api_token', (string) $token->id),
                Auditor::CATEGORY_SECURITY,
            );

            return $raw;
        });
    }
}
