<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The window as static configuration, which is what an application that
 * supplies no policy of its own gets.
 */
final readonly class ParameterAuditRetentionPolicy implements AuditRetentionPolicyInterface
{
    public function __construct(
        #[Autowire(param: 'app.audit.retention_days')]
        private int $retentionDays,
    ) {
    }

    #[\Override]
    public function retentionDays(): int
    {
        return max(1, $this->retentionDays);
    }
}
