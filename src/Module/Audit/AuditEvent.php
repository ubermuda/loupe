<?php

declare(strict_types=1);

namespace App\Module\Audit;

final readonly class AuditEvent
{
    /**
     * `category` and `channel` are strings rather than enums because a PHP enum
     * is final, so a consuming application could not add its own values.
     *
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public string $operation,
        public AuditLevel $level,
        public string $category,
        public ?AuditActorInterface $actor,
        /** Resolved once, at record time, so the row keeps a name after the account is gone. */
        public ?string $actorLabel,
        public ?AuditCredentialInterface $credential,
        public string $channel,
        public ?AuditSubject $subject,
        public array $context,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
