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
 * Dev-only page that embeds the site-review widget by project, so the reviewer
 * signs in through the OAuth popup. Used exclusively by Playwright e2e tests,
 * and not available in production (When('dev')). It deletes the `e2e-harness`
 * project's comments on every load, so each run starts from a clean state, and
 * adds the page's own origin to the allowed sites.
 *
 * Pass `?keep=1` to skip the purge, so a test can reload the harness and assert
 * the widget rehydrates the project's existing comments.
 *
 * Pass `?hide=target-two` to leave that element out of the page, so a reload
 * finds a saved multi-anchor comment with one anchor that no longer resolves.
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
            origin: $request->getSchemeAndHttpHost(),
        ));

        return $this->render('@SiteReview/dev/site_review_harness.html.twig', [
            'projectId' => $view->projectId,
            'hide' => $request->query->getString('hide'),
        ]);
    }
}
