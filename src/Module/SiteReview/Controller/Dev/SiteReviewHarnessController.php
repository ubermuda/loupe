<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Dev;

use App\Controller\AppController;
use App\Module\SiteReview\Command\PrepareHarnessCommand;
use App\Module\SiteReview\Command\PrepareHarnessHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only page that loads the site-review widget against a freshly issued site-bound
 * SiteReview API token. Used exclusively by Playwright e2e tests — not available in
 * production (When('dev')). Issues a bound token for the `e2e-harness` project and
 * deletes its comments on every load so each e2e run starts from a clean state.
 *
 * Pass `?keep=1` to skip the purge, so a test can reload the harness and assert the
 * widget rehydrates the project's existing comments. A fresh token is still minted on
 * every load (the raw value of the previous one is unrecoverable from its hash); that is
 * fine because the comments belong to the project, not the token.
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
        private readonly PrepareHarnessHandler $prepareHarness,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $view = ($this->prepareHarness)(new PrepareHarnessCommand(
            email: $request->query->getString('email'),
            keepComments: $request->query->getBoolean('keep'),
        ));

        return $this->render('@SiteReview/dev/site_review_harness.html.twig', ['token' => $view->rawToken]);
    }
}
