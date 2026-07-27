<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only page that loads the site-review widget against a freshly issued site-bound
 * SiteReview API token. Used exclusively by Playwright e2e tests — not available in
 * production (When('dev')). Issues a bound token for the `e2e-harness` project and resets
 * the draft on every load so each e2e run starts from a clean state.
 *
 * Pass `?keep=1` to skip the draft purge, so a test can reload the harness and assert
 * the widget rehydrates an existing server-side draft. A fresh token is still minted on
 * every load (the raw value of the previous one is unrecoverable from its hash); that is
 * fine because the draft belongs to the project, not the token.
 */
#[Route(
    '/dev/site-review-harness',
    name: 'app_dev_site_review_harness',
    methods: ['GET'],
)]
#[When('dev')]
final class SiteReviewHarnessController extends AppController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $email = $request->query->getString('email');
        $user = $this->users->findOneByEmail($email)
            ?? throw new \LogicException('Seed the e2e user via /dev/register-and-verify before loading the harness.');

        $project = $this->projects->findOneByOwnerAndName($user, 'e2e-harness');
        if (null === $project) {
            $project = new Project($user, 'e2e-harness');
            $this->em->persist($project);
        }

        // Deterministic starting state for every e2e run: no draft comments (unless
        // the test explicitly keeps them to exercise the widget's rehydrate path)…
        if (!$request->query->getBoolean('keep')) {
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

        return $this->render('@SiteReview/dev/harness.html.twig', ['token' => $raw]);
    }
}
