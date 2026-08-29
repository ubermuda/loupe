<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Whatever the consuming application calls a user. Both methods are nullable
 * because an unflushed entity has no identifier yet, and saying so explicitly
 * beats letting a mapping lookup guess.
 */
interface AuditActorInterface
{
    /** How to name this actor in a listing; snapshotted at record time. */
    public function auditLabel(): ?string;

    public function auditIdentifier(): ?string;
}
