<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Hard-deletes a project and everything under it in one transaction.
 * Cross-module subtrees (Review, SiteReview) are deleted by listeners on
 * ProjectDeleting; this service then removes the project row and its two
 * bound ApiTokens. Reused by delete-account (F6).
 */
final readonly class ProjectDeleter
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function delete(Project $project): void
    {
        $projectId = (string) $project->id;

        $this->em->wrapInTransaction(function () use ($project): void {
            $this->eventDispatcher->dispatch(new ProjectDeleting($project));

            $widgetToken = $project->widgetToken;
            $mcpToken = $project->mcpToken;

            $this->em->remove($project);
            foreach ([$widgetToken, $mcpToken] as $token) {
                if (null !== $token) {
                    $this->em->remove($token);
                }
            }
            $this->em->flush();
        });

        $this->logger->info('project.deleted', ['projectId' => $projectId]);
    }
}
