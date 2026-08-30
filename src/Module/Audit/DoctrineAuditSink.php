<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Buffers events and drains them in one insert per chunk when the process that
 * produced them is done.
 *
 * Buffering is what makes an audit record survive a rolled-back transaction
 * exactly as a log line does: the drain runs after the unit of work has ended,
 * so nothing it writes is inside the transaction being rolled back. That also
 * rules out the EntityManager, which would enlist the rows in that transaction
 * and is anyway unusable once an exception has closed it.
 */
final class DoctrineAuditSink implements AuditSinkInterface, ResetInterface
{
    private const string TABLE = 'audit_log';

    /** @var list<string> */
    private const array COLUMNS = [
        'id', 'operation', 'level', 'category', 'channel', 'actor_id',
        'actor_label', 'credential_id', 'subject_type', 'subject_id',
        'context', 'occurred_at',
    ];

    /** Postgres binds at most 65535 parameters per statement, and each row spends one per column. */
    private const int MAX_BIND_PARAMETERS = 65535;

    private readonly AuditLevel $minimumLevel;

    /** @var list<list<?string>> */
    private array $rows = [];

    public function __construct(
        private readonly Connection $connection,

        #[Autowire(param: 'app.audit.minimum_level')]
        string $minimumLevel,
    ) {
        $this->minimumLevel = AuditLevel::from($minimumLevel);
    }

    #[\Override]
    public function write(AuditEvent $event): void
    {
        if (!$event->level->isAtLeast($this->minimumLevel)) {
            return;
        }

        $this->rows[] = [
            // v7 rather than the entity's generator: an append-only table reads
            // and prunes in time order, and a v7 identifier already is that order.
            (string) Uuid::v7(),
            $event->operation,
            $event->level->value,
            $event->category,
            $event->channel,
            $event->actorIdentifier,
            $event->actorLabel,
            $event->credentialIdentifier,
            $event->subject?->type,
            $event->subject?->id,
            json_encode($event->context, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT),
            $event->occurredAt->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * The buffer is emptied before the first insert, so a drain that fails drops
     * its records rather than retrying them: a worker whose connection has gone
     * bad would otherwise carry one message's events into every message after
     * it. The same events are already in the log stream, and the exception
     * reaches Auditor, which reports it.
     *
     * Each chunk is attempted even after one fails, so a single rejected row —
     * an over-length operation, say — costs its own chunk and not the ones
     * behind it.
     */
    #[\Override]
    public function flush(): void
    {
        if ([] === $this->rows) {
            return;
        }

        $rows = $this->rows;
        $this->rows = [];

        $failure = null;

        foreach (array_chunk($rows, max(1, intdiv(self::MAX_BIND_PARAMETERS, count(self::COLUMNS)))) as $chunk) {
            try {
                $this->insert($chunk);
            } catch (\Throwable $e) {
                $failure ??= $e;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    #[\Override]
    public function reset(): void
    {
        $this->rows = [];
    }

    /**
     * @param non-empty-list<list<?string>> $rows
     */
    private function insert(array $rows): void
    {
        $row = '('.implode(', ', array_fill(0, count(self::COLUMNS), '?')).')';

        $this->connection->executeStatement(
            'INSERT INTO '.self::TABLE.' ('.implode(', ', self::COLUMNS).') VALUES '
                .implode(', ', array_fill(0, count($rows), $row)),
            array_merge(...$rows),
        );
    }
}
