<?php

declare(strict_types=1);

namespace App\Module\Audit;

final readonly class NullAuditActorProvider implements AuditActorProviderInterface
{
    public const string CHANNEL = 'system';

    #[\Override]
    public function currentActor(): AuditActorContext
    {
        return new AuditActorContext(null, null, null, self::CHANNEL);
    }
}
