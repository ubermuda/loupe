<?php

declare(strict_types=1);

namespace App\Module\Audit;

final readonly class AuditEvent
{
    /** How to name the actor, so the record keeps a name after the account is gone. */
    public ?string $actorLabel;

    /**
     * Derived here rather than passed in, so an event whose label and identifier
     * name different actors cannot be built. The cost is that an actor not yet
     * flushed records null for good.
     */
    public ?string $actorIdentifier;

    public ?string $credentialIdentifier;

    /**
     * `category` and `channel` are strings rather than enums because a PHP enum
     * is final, so a consuming application could not add its own values.
     *
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public string $operation,
        public AuditOutcome $outcome,
        public string $category,
        public ?AuditActorInterface $actor,
        public ?AuditCredentialInterface $credential,
        public string $channel,
        public ?AuditSubject $subject,
        public array $context,
        public \DateTimeImmutable $occurredAt,
    ) {
        $this->actorLabel = $actor?->auditLabel();
        $this->actorIdentifier = $actor?->auditIdentifier();
        $this->credentialIdentifier = $credential?->auditIdentifier();
    }
}
