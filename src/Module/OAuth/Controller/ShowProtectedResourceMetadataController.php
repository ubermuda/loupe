<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\OAuth\Command\ShowProtectedResourceMetadataCommand;
use App\Module\OAuth\Command\ShowProtectedResourceMetadataHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** A client tries the path-specific URL first, then the root one (RFC 9728, section 3.1). */
#[Route(
    '/.well-known/oauth-protected-resource/mcp',
    name: 'oauth2_protected_resource_metadata',
    methods: ['GET'],
)]
#[Route(
    '/.well-known/oauth-protected-resource',
    name: 'oauth2_protected_resource_metadata_root',
    methods: ['GET'],
)]
final class ShowProtectedResourceMetadataController extends AppController
{
    public function __construct(
        private readonly ShowProtectedResourceMetadataHandler $showMetadata,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            ($this->showMetadata)(new ShowProtectedResourceMetadataCommand()),
            headers: ['Cache-Control' => 'public, max-age=3600'],
        );
    }
}
