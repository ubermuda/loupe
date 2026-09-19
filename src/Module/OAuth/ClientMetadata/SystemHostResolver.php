<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(HostResolver::class)]
final readonly class SystemHostResolver implements HostResolver
{
    #[\Override]
    public function resolve(string $host): array
    {
        $v4 = gethostbynamel($host) ?: [];
        $v6 = @dns_get_record($host, \DNS_AAAA) ?: [];

        return [
            ...$v4,
            ...array_values(array_filter(array_map(static fn (array $record): mixed => $record['ipv6'] ?? null, $v6), \is_string(...))),
        ];
    }
}
