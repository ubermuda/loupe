<?php

declare(strict_types=1);

namespace App\Module\OAuth\Controller;

use App\Controller\AppController;
use App\Module\OAuth\Command\StartDeviceAuthorizationCommand;
use App\Module\OAuth\Command\StartDeviceAuthorizationHandler;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/oauth/device-authorization',
    name: 'oauth2_device_authorization',
    methods: ['POST'],
)]
final class StartDeviceAuthorizationController extends AppController
{
    public function __construct(
        private readonly StartDeviceAuthorizationHandler $startDeviceAuthorization,

        #[Autowire(service: 'league.oauth2_server.factory.psr_http')]
        private readonly HttpMessageFactoryInterface $psrRequests,

        #[Autowire(service: 'league.oauth2_server.factory.http_foundation')]
        private readonly HttpFoundationFactoryInterface $httpFoundation,

        #[Autowire(service: 'league.oauth2_server.factory.psr17')]
        private readonly ResponseFactoryInterface $psrResponses,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $response = ($this->startDeviceAuthorization)(new StartDeviceAuthorizationCommand($this->psrRequests->createRequest($request)));
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse($this->psrResponses->createResponse());
        }

        return $this->httpFoundation->createResponse($response);
    }
}
