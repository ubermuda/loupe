<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\ClientMetadata\ClientMetadataFetcher;
use App\Module\OAuth\ClientMetadata\ClientMetadataRefused;
use App\Module\OAuth\ClientMetadata\FetchedClientMetadata;
use App\Module\OAuth\ClientMetadata\FetchedIcon;
use App\Module\OAuth\ClientMetadata\TrustedClientIds;
use App\Module\OAuth\Entity\ClientMetadataDocument;
use App\Module\OAuth\Repository\ClientMetadataDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Makes a client_id URL usable as a client: fetches its Client ID Metadata
 * Document when the cached copy is stale, then writes the bundle client row
 * under the URL's hashed identifier. Only the authorize endpoint calls this,
 * for a signed-in user, so an anonymous request never causes a fetch.
 * The client may request the mcp scope alone.
 */
final readonly class RegisterClientMetadataDocumentHandler
{
    public function __construct(
        private ClientMetadataFetcher $fetcher,
        private TrustedClientIds $trustedClientIds,
        private ClientMetadataDocumentRepository $clientMetadataDocuments,
        private ClientManagerInterface $clients,
        private EntityManagerInterface $em,

        #[Autowire(service: 'limiter.oauth_client_metadata_fetch')]
        private RateLimiterFactoryInterface $limiter,

        #[Autowire(service: 'limiter.oauth_client_metadata_fetch_global')]
        private RateLimiterFactoryInterface $globalLimiter,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    /** @throws OAuthServerException when the document cannot be used */
    public function __invoke(RegisterClientMetadataDocumentCommand $command): void
    {
        $url = ClientIdUrl::parse($command->clientId) ?? throw self::invalidClient('The client_id is not a usable client metadata document URL.');
        if (true === $this->clientMetadataDocuments->find($url->identifier)?->isFresh($this->clock->now()) && null !== $this->clients->find($url->identifier)) {
            return;
        }

        if (!$this->globalLimiter->create('oauth_client_metadata')->consume()->isAccepted()
            || !$this->limiter->create('user:'.$command->user->id?->toRfc4122())->consume()->isAccepted()) {
            throw new OAuthServerException('Too many client metadata fetches. Try again later.', 0, 'temporarily_unavailable', 429);
        }

        try {
            $ip = $this->fetcher->vettedAddress($url);
            $fetched = $this->fetcher->fetch($url, $ip);
        } catch (ClientMetadataRefused $e) {
            $this->auditor->record('oauth.client_metadata_refused', AuditOutcome::Refused, ['url' => $url->url, 'reason' => $e->getMessage()], new AuditSubject('oauth_client', $url->identifier));

            throw self::invalidClient($e->getMessage());
        }

        // Only for a client the operator vouches for: an icon from a shared
        // host would dress an attacker's document in that host's brand.
        $icon = $this->trustedClientIds->isTrusted($url) ? $this->fetcher->fetchIcon($url, $ip) : null;

        $this->em->wrapInTransaction(function () use ($url, $fetched, $icon): void {
            $this->clientMetadataDocuments->lockForRegistration($url->identifier);
            $this->saveClient($url, $fetched);
            $this->saveDocument($url, $fetched, $icon);
        });

        $this->auditor->record('oauth.client_metadata_registered', AuditOutcome::Success, ['url' => $url->url], new AuditSubject('oauth_client', $url->identifier));
    }

    private function saveClient(ClientIdUrl $url, FetchedClientMetadata $fetched): void
    {
        // A new client starts active; a refetch keeps an operator's deactivation.
        $client = $this->clients->find($url->identifier);
        if (!$client instanceof AbstractClient) {
            $client = new Client($fetched->clientName, $url->identifier, null);
        }

        $client->setName($fetched->clientName);
        $client->setRedirectUris(...array_map(static fn (string $uri): RedirectUri => new RedirectUri($uri), $fetched->redirectUris));
        $client->setGrants(new Grant('authorization_code'), new Grant('refresh_token'));
        $client->setScopes(new Scope(ApiTokenScope::Mcp->value));
        $this->clients->save($client);
    }

    private function saveDocument(ClientIdUrl $url, FetchedClientMetadata $fetched, ?FetchedIcon $icon): void
    {
        $now = $this->clock->now();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $fetched->maxAge));
        $document = $this->clientMetadataDocuments->find($url->identifier);
        if (null === $document) {
            $document = new ClientMetadataDocument($url->identifier, $url->url, $fetched->clientName, $now, $expiresAt);
            $this->em->persist($document);
        } else {
            $this->em->refresh($document);
            $document->clientName = $fetched->clientName;
            $document->fetchedAt = $now;
            $document->expiresAt = $expiresAt;
        }

        $document->setIcon($icon);

        $this->em->flush();
    }

    private static function invalidClient(string $description): OAuthServerException
    {
        return new OAuthServerException($description, 4, 'invalid_client', 400);
    }
}
