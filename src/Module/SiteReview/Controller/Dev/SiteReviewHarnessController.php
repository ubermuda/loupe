<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only page that loads the site-review widget against a freshly issued SiteReview
 * API token. Used exclusively by Playwright e2e tests — not available in production
 * (When('dev')). The e2e test seeds the target user via /dev/register-and-verify first,
 * then this controller looks that user up by the known e2e email and issues a token for
 * it, so the seeded user is deterministic.
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
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $email = $request->query->getString('email');
        $user = $this->users->findOneByEmail($email)
            ?? throw new \LogicException('Seed the e2e user via /dev/register-and-verify before loading the harness.');

        [$token, $raw] = ApiToken::issue($user, 'e2e site-review', ApiTokenScope::SiteReview);
        $this->em->persist($token);
        $this->em->flush();

        return $this->render('@SiteReview/dev/harness.html.twig', ['token' => $raw]);
    }
}
