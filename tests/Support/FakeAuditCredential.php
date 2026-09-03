<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Ubermuda\AuditBundle\AuditCredentialInterface;

/** Stands in for whatever the application calls an API token. */
final class FakeAuditCredential implements AuditCredentialInterface
{
    public function __construct(
        public ?string $identifier = null,
    ) {
    }

    #[\Override]
    public function auditIdentifier(): ?string
    {
        return $this->identifier;
    }
}
