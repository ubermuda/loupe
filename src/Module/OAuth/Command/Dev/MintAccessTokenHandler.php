<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command\Dev;

use App\Module\OAuth\Scope\ApiScope;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Widget\WidgetClient;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Issues an access token with no browser, for a test.
 *
 * It skips the consent screen and the code exchange, and nothing else. The
 * token comes from the same repositories the grants use, so its audience, its
 * claims and its row are what a real sign-in produces, and `finalizeScopes`
 * still refuses a scope set a person could not be granted.
 *
 * It exists in dev and test alone. The three flows that issue a token in
 * production have their own tests under tests/Module/OAuth/, and this shortcut
 * must never stand in for them.
 */
#[Autoconfigure(public: true)]
#[When('dev')]
#[When('test')]
final readonly class MintAccessTokenHandler
{
    public function __construct(
        #[Autowire(service: 'league.oauth2_server.repository.access_token')]
        private AccessTokenRepositoryInterface $accessTokens,

        #[Autowire(service: 'league.oauth2_server.repository.scope')]
        private ScopeRepositoryInterface $scopes,

        #[Autowire(service: 'league.oauth2_server.repository.client')]
        private ClientRepositoryInterface $clients,

        // The same two the bundle reads, because it exposes its key as no
        // service or parameter of its own.
        #[Autowire(env: 'resolve:OAUTH_PRIVATE_KEY')]
        private string $privateKeyPath,

        #[Autowire(env: 'OAUTH_PRIVATE_KEY_PASSPHRASE')]
        private string $privateKeyPassphrase,
    ) {
    }

    /**
     * A grant carries a binding when a scope needs one, and carries none
     * otherwise, so `agent` alone takes no project and `mcp` always takes one.
     * With no project named, the grant covers every project of the owner.
     *
     * @return non-empty-string
     */
    public function __invoke(MintAccessTokenCommand $command): string
    {
        $identifiers = $command->scopes;
        $parsed = array_map(
            static fn (string $scope): ApiScope => ApiScope::tryFrom($scope) ?? throw new \LogicException('no scope named '.$scope.'.'),
            $command->scopes,
        );

        if (GrantedScope::anyNeedsProject($parsed)) {
            $identifiers[] = null !== $command->project
                ? GrantedScope::projectScope($command->project->id ?? throw new \LogicException('a persisted project always has an id.'))
                : GrantedScope::ALL_PROJECTS;
        } elseif (null !== $command->project) {
            throw new \LogicException('these scopes take no project: '.implode(' ', $command->scopes).'.');
        }

        // League limits a client to the scopes it registered, so the client has
        // to be the one that asks for these scopes in production.
        $clientId = \in_array(WidgetClient::SCOPE, $command->scopes, true) ? WidgetClient::ID : 'loupe-cli';
        $client = $this->clients->getClientEntity($clientId)
            ?? throw new \LogicException($clientId.' is not registered. A migration registers it.');

        $requested = array_map(
            fn (string $identifier): ScopeEntityInterface => $this->scopes->getScopeEntityByIdentifier($identifier)
                ?? throw new \LogicException('no scope named '.$identifier.'.'),
            $identifiers,
        );

        $userId = (string) ($command->owner->id ?? throw new \LogicException('a persisted user always has an id.'));
        $granted = $this->scopes->finalizeScopes($requested, 'authorization_code', $client, $userId);

        $token = $this->accessTokens->getNewToken($client, $granted, $userId);
        $token->setIdentifier(bin2hex(random_bytes(40)));
        $token->setExpiryDateTime(new \DateTimeImmutable('+1 hour'));
        $this->accessTokens->persistNewAccessToken($token);
        // The third argument switches off league's key permission check, as the
        // bundle does for its own key. Dev turns its notice into an exception,
        // and the file ships at 0666 in a container.
        $token->setPrivateKey(new CryptKey($this->privateKeyPath, '' !== $this->privateKeyPassphrase ? $this->privateKeyPassphrase : null, false));

        $jwt = $token->toString();

        return '' !== $jwt ? $jwt : throw new \LogicException('the minted token is empty.');
    }
}
