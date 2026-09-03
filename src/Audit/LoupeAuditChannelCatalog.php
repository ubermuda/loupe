<?php

declare(strict_types=1);

namespace App\Audit;

use Ubermuda\AuditBundle\AuditChannelCatalogInterface;

/**
 * The audit package holds a channel as a plain string, so this application
 * names its own set. The enum stays the one source of truth for it.
 */
final readonly class LoupeAuditChannelCatalog implements AuditChannelCatalogInterface
{
    #[\Override]
    public function channels(): array
    {
        return array_map(
            static fn (AuditChannel $channel): string => $channel->value,
            AuditChannel::cases(),
        );
    }
}
