<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Enforces the retention window on the trail.
 *
 * One bulk statement through the DBAL, like the sink that fills the table: the
 * EntityManager would hydrate every doomed row first, and a retention sweep is
 * the one operation whose row count is unbounded.
 *
 * Every caller purges through here, and here reads the policy on each call, so
 * the scheduled sweep and a hand-run audit:purge cannot disagree about the
 * window.
 */
final readonly class AuditLogPurger
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AuditRetentionPolicyInterface $retention,
    ) {
    }

    /** Deletes every record older than the window. A record exactly on the cutoff is kept. */
    public function purge(): int
    {
        $cutoff = $this->clock->now()->sub(new \DateInterval('P'.$this->retention->retentionDays().'D'));

        return (int) $this->connection->executeStatement(
            'DELETE FROM audit_log WHERE occurred_at < :cutoff',
            ['cutoff' => $cutoff->format('Y-m-d H:i:s.u')],
        );
    }
}
