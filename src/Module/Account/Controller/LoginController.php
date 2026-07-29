<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Service\RegistrationGate;
use App\Routing\PaywallExempt;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

#[PaywallExempt]
#[Route('/login', name: 'app_login')]
class LoginController extends AppController
{
    public function __construct(
        private readonly AuthenticationUtils $authenticationUtils,
        private readonly RegistrationGate $registrationGate,
    ) {
    }

    public function __invoke(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('@Account/security/login.html.twig', [
            'last_username' => $this->authenticationUtils->getLastUsername(),
            'error' => $this->authenticationUtils->getLastAuthenticationError(),
            // /register 404s on an instance that is not installed or has sign-up
            // switched off, so the "create an account" links must disappear with
            // it rather than sending visitors to a dead end.
            'registration_open' => $this->registrationGate->allowsNewAccounts(),
        ]);
    }
}
