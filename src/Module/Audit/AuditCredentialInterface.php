<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Marks whatever the consuming application calls an API token. Stays empty for
 * the same reason as AuditActorInterface.
 */
interface AuditCredentialInterface
{
}
