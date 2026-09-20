<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The trusted client ids of the deployment's own configuration.
 *
 * An entry is a full client_id URL, or an origin the operator believes serves
 * one tenant. A bare host never matches, because a document on a shared host
 * such as a CDN would then borrow the trust of every other document there.
 */
#[AsAlias(TrustedClientIds::class)]
final readonly class ConfiguredTrustedClientIds implements TrustedClientIds
{
    /** @var list<string> */
    private array $entries;

    /** @param list<string> $trusted */
    public function __construct(
        #[Autowire(param: 'app.oauth.trusted_client_ids')]
        array $trusted,
    ) {
        $this->entries = array_values(array_filter(array_map(
            static fn (mixed $entry): string => \is_string($entry) ? rtrim(trim($entry), '/') : '',
            $trusted,
        ), static fn (string $entry): bool => str_starts_with($entry, 'https://') && '' !== substr($entry, \strlen('https://'))));
    }

    #[\Override]
    public function isTrusted(ClientIdUrl $url): bool
    {
        $candidate = rtrim($url->url, '/');

        return array_any($this->entries, static fn (string $entry): bool => $entry === $candidate || str_starts_with($candidate, $entry.'/'));
    }
}
