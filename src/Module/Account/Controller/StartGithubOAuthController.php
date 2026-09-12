<?php

declare(strict_types=1);

namespace App\Module\Account\Controller;

use App\Controller\AppController;
use App\Module\Account\Command\StartOAuthCommand;
use App\Module\Account\Command\StartOAuthHandler;
use App\Module\Account\Entity\SocialProvider;
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
        private readonly StartOAuthHandler $startOAuth,
    ) {
    }

    public function __invoke(): Response
    {
        $view = ($this->startOAuth)(new StartOAuthCommand(SocialProvider::Github));

        return $this->redirect($view->authorizationUrl);
    }
}
