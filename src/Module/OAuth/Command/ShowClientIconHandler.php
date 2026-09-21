<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\ClientMetadata\FetchedIcon;
use App\Module\OAuth\Repository\ClientMetadataDocumentRepository;

final readonly class ShowClientIconHandler
{
    public function __construct(
        private ClientMetadataDocumentRepository $clientMetadataDocuments,
    ) {
    }

    /** Null when this client has no stored icon. */
    public function __invoke(ShowClientIconCommand $command): ?FetchedIcon
    {
        $document = $this->clientMetadataDocuments->find($command->clientIdentifier);
        $bytes = $document?->iconBytes();

        return null === $bytes || null === $document?->iconType ? null : new FetchedIcon($bytes, $document->iconType);
    }
}
