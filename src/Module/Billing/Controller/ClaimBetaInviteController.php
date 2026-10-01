<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Billing\Command\ClaimBetaInviteCommand;
use App\Module\Billing\Command\ClaimBetaInviteHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * The link page shows the outcome, so every claim redirects back to it.
 */
#[CsrfToken('billing-beta-invite-claim')]
#[Route(
    '/beta/{token}',
    name: 'app_billing_beta_invite_claim',
    methods: ['POST'],
)]
final class ClaimBetaInviteController extends AppController
{
    public function __construct(
        private readonly ClaimBetaInviteHandler $claimBetaInvite,
    ) {
    }

    public function __invoke(string $token): Response
    {
        // A signed-out POST claims nothing. The link page sends that visitor to sign-up.
        $user = $this->getUser();
        if ($user instanceof User) {
            ($this->claimBetaInvite)(new ClaimBetaInviteCommand($token, $user));
        }

        return $this->redirectToRoute('app_billing_beta_invite', ['token' => $token]);
    }
}
