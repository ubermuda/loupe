<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\SocialProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;

/**
 * Which client answers for a provider, and which scopes the app asks it for.
 * `redirect()` writes the OAuth state to the session before it builds the URL,
 * so the call has to happen here rather than being recreated from its parts.
 */
final readonly class StartOAuthHandler
{
    public function __construct(
        private ClientRegistry $clientRegistry,
    ) {
    }

    public function __invoke(StartOAuthCommand $command): StartOAuthView
    {
        [$client, $scopes] = match ($command->provider) {
            SocialProvider::Google => ['google_main', ['email', 'profile']],
            // user:email is required: the verified primary email is only exposed
            // by GET /user/emails, and without a verified email the login is
            // rejected.
            SocialProvider::Github => ['github_main', ['read:user', 'user:email']],
        };

        return new StartOAuthView(
            $this->clientRegistry->getClient($client)->redirect($scopes, [])->getTargetUrl(),
        );
    }
}
