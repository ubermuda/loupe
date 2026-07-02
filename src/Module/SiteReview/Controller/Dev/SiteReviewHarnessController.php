<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Repository\UserRepository;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Repository\SiteRepository;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only page that loads the site-review widget against a freshly issued site-bound
 * SiteReview API token. Used exclusively by Playwright e2e tests — not available in
 * production (When('dev')). Issues a bound token for the `e2e-harness` site and resets
 * the draft on every load so each e2e run starts from a clean state.
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
        private readonly SiteRepository $sites,
        private readonly SiteReviewRepository $siteReviews,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $email = $request->query->getString('email');
        $user = $this->users->findOneByEmail($email)
            ?? throw new \LogicException('Seed the e2e user via /dev/register-and-verify before loading the harness.');

        $site = $this->sites->findOneByOwnerAndName($user, 'e2e-harness');
        if (null === $site) {
            $site = new Site($user, 'e2e-harness');
            $this->em->persist($site);
        }

        // Deterministic starting state for every e2e run: no draft review…
        $draft = $this->siteReviews->findOneInProgress($site);
        if (null !== $draft) {
            $this->em->remove($draft);
        }

        // …and a fresh bound token (the old one, if any, is discarded).
        $previous = $site->token;
        [$token, $raw] = ApiToken::issue($user, 'e2e site-review', ApiTokenScope::SiteReview);
        $site->token = $token;
        $this->em->persist($token);
        if (null !== $previous) {
            $this->em->remove($previous);
        }
        $this->em->flush();

        return $this->render('@SiteReview/dev/harness.html.twig', ['token' => $raw]);
    }
}
