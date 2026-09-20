<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\OAuth\Command\ShowAuthorizationServerMetadataCommand;
use App\Module\OAuth\Command\ShowAuthorizationServerMetadataHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/.well-known/oauth-authorization-server',
    name: 'oauth2_authorization_server_metadata',
    methods: ['GET'],
)]
final class ShowAuthorizationServerMetadataController extends AppController
{
    public function __construct(
        private readonly ShowAuthorizationServerMetadataHandler $showMetadata,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            ($this->showMetadata)(new ShowAuthorizationServerMetadataCommand()),
            headers: ['Cache-Control' => 'public, max-age=3600'],
        );
    }
}
