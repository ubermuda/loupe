<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Never reached for a valid flow: SocialAuthenticator::supports() intercepts this
 * route on the firewall before the controller runs. It exists so the router can
 * generate the callback URL, so a stray hit without OAuth parameters degrades to
 * the login page, and — via the attribute below — so the callback URL 404s while
 * the provider is switched off.
 */
#[RequireFeatureFlag('auth.google.enabled')]
#[Route(
    '/oauth/google/check',
    name: 'app_oauth_check_google',
    methods: ['GET'],
)]
class GoogleOAuthCheckController extends AppController
{
    public function __invoke(): Response
    {
        return $this->redirectToRoute('app_login');
    }
}
