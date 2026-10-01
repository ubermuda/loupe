<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Account\Registration\RegistrationPasses;
use App\Module\Billing\Command\BetaInviteOutcome;
use App\Module\Billing\Command\ClaimBetaInviteCommand;
use App\Module\Billing\Command\ClaimBetaInviteHandler;
use App\Module\Billing\Command\OpenBetaInviteCommand;
use App\Module\Billing\Command\OpenBetaInviteHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * A signed-in user claims the link. A signed-out visitor carries it to
 * sign-up, where the registration pass redeems it. Anything else redirects
 * back to the link page, which shows the outcome.
 */
#[CsrfToken('billing-beta-invite-claim')]
#[Route(
    '/beta/{token}',
    name: 'app_billing_beta_invite_claim',
    methods: ['POST'],
)]
final class ClaimBetaInviteController extends AppController
{
    use TargetPathTrait;

    public function __construct(
        private readonly ClaimBetaInviteHandler $claimBetaInvite,
        private readonly OpenBetaInviteHandler $openBetaInvite,
    ) {
    }

    public function __invoke(Request $request, string $token): Response
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            ($this->claimBetaInvite)(new ClaimBetaInviteCommand($token, $user));
        } elseif (BetaInviteOutcome::SignUp === ($this->openBetaInvite)(new OpenBetaInviteCommand($token, null))->outcome) {
            $session = $request->getSession();
            $session->set(RegistrationPasses::SESSION_KEY, $token);
            // A visitor who signs in to an existing account comes back to claim it.
            $this->saveTargetPath(
                $session,
                'main',
                $this->generateUrl('app_billing_beta_invite', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            );

            return $this->redirectToRoute('app_register');
        }

        return $this->redirectToRoute('app_billing_beta_invite', ['token' => $token]);
    }
}
