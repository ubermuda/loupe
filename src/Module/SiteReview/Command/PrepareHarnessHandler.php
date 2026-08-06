<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PrepareHarnessHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private ProjectRepository $projects,
        private SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(PrepareHarnessCommand $command): PrepareHarnessView
    {
        $user = $this->users->findOneByEmail($command->email)
            ?? throw new \LogicException('Seed the e2e user via /dev/register-and-verify before loading the harness.');

        $project = $this->projects->findOneByOwnerAndName($user, 'e2e-harness');
        if (null === $project) {
            $project = new Project($user, 'e2e-harness');
            $this->em->persist($project);
        }

        // Deterministic starting state for every e2e run: no draft comments (unless
        // the test explicitly keeps them to exercise the widget's rehydrate path)…
        if (!$command->keepDraft) {
            foreach ($this->siteReviewComments->findDraftForProject($project) as $draft) {
                $this->em->remove($draft);
            }
        }

        // …and a fresh bound token (the old one, if any, is discarded).
        $previous = $project->widgetToken;
        [$token, $raw] = ApiToken::issue($user, 'e2e site-review', ApiTokenScope::SiteReview);
        $project->widgetToken = $token;
        $this->em->persist($token);
        if (null !== $previous) {
            $this->em->remove($previous);
        }
        $this->em->flush();

        return new PrepareHarnessView($raw);
    }
}
