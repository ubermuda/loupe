<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

#[RequireFeatureFlag('auth.github.enabled')]
#[Route(
    '/oauth/github',
    name: 'app_oauth_start_github',
    methods: ['GET'],
)]
class StartGithubOAuthController extends AppController
{
    public function __construct(
        private readonly ClientRegistry $clientRegistry,
    ) {
    }

    public function __invoke(): Response
    {
        // user:email is required: the verified primary email is only exposed by
        // GET /user/emails, and without a verified email the login is rejected.
        return $this->clientRegistry->getClient('github_main')->redirect(['read:user', 'user:email'], []);
    }
}
