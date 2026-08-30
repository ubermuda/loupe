<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Audit\AuditActorInterface;

/** Stands in for whatever the application calls a user, with both answers writable. */
final class FakeAuditActor implements AuditActorInterface
{
    public function __construct(
        public ?string $label = null,
        public ?string $identifier = null,
    ) {
    }

    #[\Override]
    public function auditLabel(): ?string
    {
        return $this->label;
    }

    #[\Override]
    public function auditIdentifier(): ?string
    {
        return $this->identifier;
    }
}
