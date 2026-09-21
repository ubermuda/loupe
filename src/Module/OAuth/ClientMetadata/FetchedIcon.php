<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

/** An icon Loupe fetched and now serves from its own origin. */
final readonly class FetchedIcon
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
