<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.audit_sink')]
interface AuditSinkInterface
{
    public function write(AuditEvent $event): void;

    /**
     * A no-op for a sink that writes immediately.
     */
    public function flush(): void;
}
