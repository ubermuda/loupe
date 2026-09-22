<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Command\EnsureHarnessProjectCommand;
use App\Module\Project\Command\EnsureHarnessProjectHandler;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PrepareHarnessHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private EnsureHarnessProjectHandler $ensureHarnessProject,
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(PrepareHarnessCommand $command): PrepareHarnessView
    {
        $user = $this->users->findOneByEmail($command->email)
            ?? throw new \LogicException('Seed the e2e user via /dev/register-and-verify before loading the harness.');

        $project = ($this->ensureHarnessProject)(new EnsureHarnessProjectCommand($user, 'e2e-harness'));

        // Deterministic starting state for every e2e run: no comments at all,
        // whatever status a previous run left them in (unless the test explicitly
        // keeps them to exercise the widget's rehydrate path)…
        if (!$command->keepComments) {
            foreach ($this->siteReviewComments->findForProject($project) as $comment) {
                $this->em->remove($comment);
            }
        }

        // …and a project the harness page may sign in against.
        $project->forwardsToAgent = false;
        if (!\in_array($command->origin, $project->allowedOrigins, true)) {
            $project->allowedOrigins = [...$project->allowedOrigins, $command->origin];
        }
        $this->em->flush();

        return new PrepareHarnessView((string) $project->id);
    }
}
