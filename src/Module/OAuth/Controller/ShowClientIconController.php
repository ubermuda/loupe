<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\OAuth\Command\ShowClientIconCommand;
use App\Module\OAuth\Command\ShowClientIconHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the icon Loupe fetched from a client's host. Loupe's own origin
 * serves it, so the consent page tells that host nothing about the user and
 * needs no CSP origin. The bytes come from a fetch that accepted a short
 * allowlist of image types, and nosniff holds the browser to it.
 */
#[Route(
    '/oauth/client-icon/{identifier}',
    name: 'oauth2_client_icon',
    requirements: ['identifier' => '[a-z0-9-]{1,32}'],
    methods: ['GET'],
)]
final class ShowClientIconController extends AppController
{
    public function __construct(
        private readonly ShowClientIconHandler $showClientIcon,
    ) {
    }

    public function __invoke(string $identifier): Response
    {
        $icon = ($this->showClientIcon)(new ShowClientIconCommand($identifier))
            ?? throw new NotFoundHttpException('This client has no icon.');

        return new Response($icon->bytes, Response::HTTP_OK, [
            'Content-Type' => $icon->contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
