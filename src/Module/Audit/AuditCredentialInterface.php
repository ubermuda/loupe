<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Whatever the consuming application calls an API token. No label counterpart
 * to AuditActorInterface: a token has no display name, and inventing one for
 * the sake of symmetry would put a fiction in the trail.
 */
interface AuditCredentialInterface
{
    public function auditIdentifier(): ?string;
}
