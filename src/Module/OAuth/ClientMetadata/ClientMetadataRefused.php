<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

/** A client metadata document that cannot be used. The message is safe to show to the client. */
final class ClientMetadataRefused extends \RuntimeException
{
}
