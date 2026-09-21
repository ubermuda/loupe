<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\ClientMetadata\ConfiguredTrustedClientIds;
use App\Module\OAuth\ClientMetadata\TrustedClientIds;

/**
 * A trust list a test changes between requests. The container refuses a second
 * set() once a service is built, so one instance serves the whole test and the
 * entries move instead. Matching stays the real implementation's.
 */
final class MutableTrustedClientIds implements TrustedClientIds
{
    /** @param list<string> $entries */
    public function __construct(
        public array $entries = [],
    ) {
    }

    #[\Override]
    public function isTrusted(ClientIdUrl $url): bool
    {
        return new ConfiguredTrustedClientIds($this->entries)->isTrusted($url);
    }
}
