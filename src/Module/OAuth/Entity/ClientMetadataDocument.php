<?php

declare(strict_types=1);

namespace App\Module\OAuth\Entity;

use App\Module\OAuth\Repository\ClientMetadataDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The client_id URL behind a bundle client row, and how long its fetched
 * document stays trusted. The key is the bundle's client identifier, a hash
 * of the URL, because the bundle's column is too short for a URL.
 */
#[ORM\Entity(repositoryClass: ClientMetadataDocumentRepository::class)]
#[ORM\Table(name: 'oauth_client_metadata_document')]
class ClientMetadataDocument
{
    public function __construct(
        #[ORM\Column(length: 32)]
        #[ORM\Id]
        public readonly string $clientIdentifier,

        #[ORM\Column(length: 255)]
        public readonly string $url,

        #[ORM\Column(length: 128)]
        public string $clientName,

        #[ORM\Column]
        public \DateTimeImmutable $fetchedAt,

        #[ORM\Column]
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public function isFresh(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt > $now;
    }
}
