<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Registration\RegistrationPasses;
use App\Module\Billing\Command\BetaInviteOutcome;
use App\Module\Billing\Command\OpenBetaInviteCommand;
use App\Module\Billing\Command\OpenBetaInviteHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A signed-in user redeems at once. A signed-out visitor carries the token to
 * sign-up, where the registration pass redeems it inside the account's
 * creation transaction.
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

    public function __invoke(Request $request, string $token): Response
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
                $request->getSession()->set(RegistrationPasses::SESSION_KEY, $token);

                return $this->redirectToRoute('app_register');
            case BetaInviteOutcome::Redeemed:
                return $this->render('@Billing/redeem_beta_invite.html.twig', [
                    'hasLiveSubscription' => $view->hasLiveSubscription,
                ]);
        }
    }
}
