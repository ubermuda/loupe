<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

#[RequireFeatureFlag('auth.google.enabled')]
#[Route(
    '/oauth/google',
    name: 'app_oauth_start_google',
    methods: ['GET'],
)]
class StartGoogleOAuthController extends AppController
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->clientRegistry->getClient('google_main')->redirect(['email', 'profile'], []);
    }
}
