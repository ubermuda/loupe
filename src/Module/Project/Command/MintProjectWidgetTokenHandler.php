<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class MintProjectWidgetTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return non-empty-string the raw token, shown to the user exactly once
     */
    public function __invoke(MintProjectWidgetTokenCommand $command): string
    {
        $project = $command->project;

        // Serialize concurrent mints for the same project with a row lock: two
        // requests that both passed the check could otherwise each persist a
        // token, leaving the losing one valid but bound to no project — which
        // would slip past the account-vs-widget distinction on the site-review
        // API. Re-check against committed state (not the possibly-stale in-memory
        // association) once the lock is held.
        return $this->em->wrapInTransaction(function () use ($project): string {
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            if ($this->projects->hasCommittedWidgetToken($project)) {
                $this->logger->info('project.widget_token_mint_rejected', [
                    'projectId' => (string) $project->id,
                ]);
                throw new DomainErrors(['token' => 'project.error.widget_token_already_minted']);
            }

            // ApiToken.label is a 100-char column while Project.name allows 100 — truncate to fit.
            [$token, $raw] = ApiToken::issue($project->owner, 'Widget: '.mb_substr($project->name, 0, 92), ApiTokenScope::SiteReview);
            $project->widgetToken = $token;
            $this->em->persist($token);
            $this->em->flush();

            $this->logger->info('project.widget_token_minted', [
                'projectId' => (string) $project->id,
                'tokenId' => (string) $token->id,
            ]);

            return $raw;
        });
    }
}
