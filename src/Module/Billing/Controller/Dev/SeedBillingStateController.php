<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\SeedBillingStateCommand;
use App\Module\Billing\Command\SeedBillingStateHandler;
use App\Routing\PaywallExempt;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only endpoint that puts billing into a chosen state: flips the
 * `billing.enabled` flag, seeds the authenticated user's billing profile into
 * a named lifecycle state, and, on request, runs the trial-end sweep. Used
 * exclusively by Playwright e2e tests — not available in production
 * (When('dev')). The route is allowlisted in RequireSubscriptionListener so a
 * paywalled session can still call it to switch billing back off.
 *
 * `state` values (each fully re-seeds the profile, so states can be applied
 * in any order):
 *   - fresh-trial:          an untouched trial with 14 days left — the reset/cleanup state
 *   - expired-trial:        a trial that ended yesterday, not yet swept
 *   - canceled-past-period: a canceled subscription whose paid period ended yesterday, not yet swept
 *   - disabled:             the post-sweep result of an expired trial (account disabled, survey marked)
 *
 * `sweep=1` runs the trial-end sweep synchronously and returns its counts. The
 * feature-flag reader is request-cached, so flip `enabled` and run the sweep
 * in separate requests — a same-request flip may be invisible to the sweeper.
 */
#[PaywallExempt]
#[Route(
    '/dev/billing-state',
    name: 'app_dev_billing_state',
    methods: ['GET'],
)]
#[When('dev')]
final class SeedBillingStateController extends AppController
{
    public function __construct(
        private readonly SeedBillingStateHandler $seedBillingState,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $view = ($this->seedBillingState)(new SeedBillingStateCommand(
            billingEnabled: $request->query->getBoolean('enabled'),
            state: $request->query->getString('state'),
            user: $user instanceof User ? $user : null,
            sweep: $request->query->getBoolean('sweep'),
        ));

        $payload = ['billingEnabled' => $view->billingEnabled];
        if (null !== $view->state) {
            $payload['state'] = $view->state;
        }
        if (null !== $view->sweep) {
            $payload['sweep'] = [
                'disabled' => $view->sweep->disabled,
                'churnedSurveys' => $view->sweep->churnedSurveys,
                'subscriberSurveys' => $view->sweep->subscriberSurveys,
                'cancelSurveys' => $view->sweep->cancelSurveys,
                'failed' => $view->sweep->failed,
            ];
        }

        return $this->json($payload);
    }
}
