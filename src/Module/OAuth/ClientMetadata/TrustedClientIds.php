<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

/**
 * The client ids an operator vouches for. A trusted client shows its host
 * alone on the consent page; every other client shows its whole client_id.
 *
 * ConfiguredTrustedClientIds reads the deployment's list. A later
 * implementation can read entries an administrator adds, and compose the two,
 * with no change to the consent code and no migration of these entries.
 */
interface TrustedClientIds
{
    public function isTrusted(ClientIdUrl $url): bool;
}
