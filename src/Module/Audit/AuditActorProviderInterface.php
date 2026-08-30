<?php

declare(strict_types=1);

namespace App\Module\Audit;

interface AuditActorProviderInterface
{
    public function currentActor(): AuditActorContext;
}
