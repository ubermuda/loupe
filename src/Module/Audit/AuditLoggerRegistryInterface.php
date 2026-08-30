<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Psr\Log\LoggerInterface;

/**
 * Resolves the logger an event's category writes to. Monolog channels are
 * separate logger services, so a channel cannot be looked up from a name the
 * package holds — the consuming application supplies the mapping instead, and
 * the package never learns its channel names.
 */
interface AuditLoggerRegistryInterface
{
    /** Null when the category has no logger of its own. */
    public function loggerFor(string $category): ?LoggerInterface;
}
