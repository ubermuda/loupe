<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Device\UserCode;
use App\Module\OAuth\Scope\GrantedScope;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The device authorization endpoint (RFC 8628). League lets a client that
 * lists no grant use every grant, so only a client that names the device grant
 * may start a flow here. The scope must need no project, because the
 * verification page has no project picker.
 */
final readonly class StartDeviceAuthorizationHandler
{
    public function __construct(
        #[Autowire(service: 'league.oauth2_server.authorization_server')]
        private AuthorizationServer $server,
        private ClientManagerInterface $clients,

        #[Autowire(service: 'league.oauth2_server.factory.psr17')]
        private ResponseFactoryInterface $psrResponses,
    ) {
    }

    /** @throws OAuthServerException */
    public function __invoke(StartDeviceAuthorizationCommand $command): ResponseInterface
    {
        $body = (array) $command->request->getParsedBody();
        $clientId = $body['client_id'] ?? $command->request->getServerParams()['PHP_AUTH_USER'] ?? null;
        $client = \is_string($clientId) ? $this->clients->find($clientId) : null;
        if (null !== $client && !\in_array(UserCode::DEVICE_GRANT, array_map(strval(...), $client->getGrants()), true)) {
            throw OAuthServerException::unauthorizedClient();
        }

        $scope = $body['scope'] ?? null;
        $granted = \is_string($scope) ? GrantedScope::fromScopes(array_values(array_filter(explode(' ', $scope), static fn (string $part): bool => '' !== $part))) : null;
        if (null === $granted || null !== $granted->projectId) {
            throw OAuthServerException::invalidScope(\is_string($scope) ? $scope : '');
        }

        return $this->server->respondToDeviceAuthorizationRequest($command->request, $this->psrResponses->createResponse());
    }
}
