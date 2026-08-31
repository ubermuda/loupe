<?php

declare(strict_types=1);

namespace App\Audit\Command\Admin;

use App\Module\Audit\AuditOutcome;

/**
 * One rendered line of the trail. The actor is the label the record stored, not
 * the account it points at: the association is lazy, and the label is what
 * survives the account.
 */
final readonly class AuditLogRow
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public string $id,
        public string $operation,
        public AuditOutcome $outcome,
        public string $category,
        public string $channel,
        public \DateTimeImmutable $occurredAt,
        public array $context,
        public ?string $actorLabel,
        public ?string $subjectType,
        public ?string $subjectId,
    ) {
    }
}
