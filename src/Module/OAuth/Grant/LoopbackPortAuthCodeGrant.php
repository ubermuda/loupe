<?php

declare(strict_types=1);

namespace App\Module\OAuth\Grant;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;

/** League's authorization code grant, with LoopbackRedirectUri as its redirect matcher. */
final class LoopbackPortAuthCodeGrant extends AuthCodeGrant
{
    #[\Override]
    protected function validateRedirectUri(string $redirectUri, ClientEntityInterface $client, ServerRequestInterface $request): void
    {
        if (!LoopbackRedirectUri::isRegistered($redirectUri, (array) $client->getRedirectUri())) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidClient($request);
        }
    }
}
