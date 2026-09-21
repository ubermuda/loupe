<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

use App\Module\OAuth\Repository\ClientMetadataDocumentRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Maps a client_id URL to the hashed identifier of its client row. This
 * never fetches: the authorize endpoint registers the document first, and
 * the public token endpoint only finds what is registered. A hashed
 * identifier sent as a client_id is unknown, so every use goes through a URL.
 */
#[AsDecorator('league.oauth2_server.repository.client')]
final readonly class MetadataDocumentClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private ClientRepositoryInterface $inner,
        private ClientMetadataDocumentRepository $clientMetadataDocuments,
    ) {
    }

    #[\Override]
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $identifier = $this->identifierFor($clientIdentifier);

        return null === $identifier ? null : $this->inner->getClientEntity($identifier);
    }

    #[\Override]
    public function validateClient(string $clientIdentifier, #[\SensitiveParameter] ?string $clientSecret, ?string $grantType): bool
    {
        $identifier = $this->identifierFor($clientIdentifier);

        return null !== $identifier && $this->inner->validateClient($identifier, $clientSecret, $grantType);
    }

    private function identifierFor(string $clientId): ?string
    {
        if (str_starts_with($clientId, ClientIdUrl::IDENTIFIER_PREFIX)) {
            return null;
        }

        if (!ClientIdUrl::isCandidate($clientId)) {
            return $clientId;
        }

        $url = ClientIdUrl::parse($clientId);

        return null !== $url && null !== $this->clientMetadataDocuments->find($url->identifier) ? $url->identifier : null;
    }
}
