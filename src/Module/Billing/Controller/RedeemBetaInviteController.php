<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\BetaInviteOutcome;
use App\Module\Billing\Command\OpenBetaInviteCommand;
use App\Module\Billing\Command\OpenBetaInviteHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reads only, and writes nothing to the session: Turbo prefetches a link on
 * hover, and a GET writes the session row without its lock. Every button on
 * these pages POSTs to ClaimBetaInviteController.
 */
#[Route(
    '/beta/{token}',
    name: 'app_billing_beta_invite',
    methods: ['GET'],
)]
final class RedeemBetaInviteController extends AppController
{
    public function __construct(
        private readonly OpenBetaInviteHandler $openBetaInvite,
    ) {
    }

    public function __invoke(string $token): Response
    {
        $user = $this->getUser();
        $view = ($this->openBetaInvite)(new OpenBetaInviteCommand($token, $user instanceof User ? $user : null));

        switch ($view->outcome) {
            case BetaInviteOutcome::Invalid:
                return $this->render(
                    '@Billing/redeem_beta_invite_invalid.html.twig',
                    response: new Response(status: Response::HTTP_NOT_FOUND),
                );
            case BetaInviteOutcome::RegistrationDisabled:
                throw $this->createNotFoundException();
            case BetaInviteOutcome::SignUp:
                return $this->render('@Billing/redeem_beta_invite_sign_up.html.twig', ['token' => $token]);
            case BetaInviteOutcome::Claimable:
                return $this->render('@Billing/redeem_beta_invite_claim.html.twig', ['token' => $token]);
            case BetaInviteOutcome::Redeemed:
                return $this->render('@Billing/redeem_beta_invite.html.twig', [
                    'hasLiveSubscription' => $view->hasLiveSubscription,
                ]);
        }
    }
}
