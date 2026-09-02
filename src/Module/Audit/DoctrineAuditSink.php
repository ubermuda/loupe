<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
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
        'id', 'operation', 'outcome', 'category', 'channel', 'actor_id',
        'actor_label', 'credential_id', 'subject_type', 'subject_id',
        'context', 'occurred_at',
    ];

    /** Postgres binds at most 65535 parameters per statement, and each row spends one per column. */
    private const int MAX_BIND_PARAMETERS = 65535;

    /** @var list<list<?string>> */
    private array $rows = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly ManagerRegistry $managers,
    ) {
    }

    #[\Override]
    public function write(AuditEvent $event): void
    {
        $this->rows[] = [
            // v7 rather than the entity's generator: an append-only table reads
            // and prunes in time order, and a v7 identifier already is that order.
            (string) Uuid::v7(),
            $event->operation,
            $event->outcome->value,
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

        $template = $this->rowTemplate();
        $failure = null;

        foreach (array_chunk($rows, max(1, intdiv(self::MAX_BIND_PARAMETERS, count(self::COLUMNS)))) as $chunk) {
            try {
                $this->insert($template, $chunk);
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
     * The table and identifier column the interface resolves to, or null when
     * the consuming application maps no implementation for it.
     *
     * @param class-string $target
     *
     * @return ?array{string, string}
     */
    private function identityTableFor(string $target): ?array
    {
        try {
            $metadata = $this->managers->getManager()->getClassMetadata($target);
        } catch (\Throwable) {
            return null;
        }

        return $metadata instanceof ClassMetadata
            ? [$metadata->getTableName(), $metadata->getSingleIdentifierColumnName()]
            : null;
    }

    /**
     * An account can be deleted before the record of its own deletion drains,
     * and the foreign key would then reject the whole chunk. Each reference is
     * resolved by the insert itself rather than by a lookup before it, so a row
     * that has gone lands as NULL — the end state the column's ON DELETE SET
     * NULL already declares — with no window in between for it to go missing.
     */
    private function rowTemplate(): string
    {
        $references = [
            'actor_id' => AuditActorInterface::class,
            'credential_id' => AuditCredentialInterface::class,
        ];

        $placeholders = [];
        foreach (self::COLUMNS as $column) {
            $table = isset($references[$column]) ? $this->identityTableFor($references[$column]) : null;

            // One placeholder either way, so a row still spends exactly one
            // bind parameter per column and the chunk size stays correct.
            $placeholders[] = null === $table
                ? '?'
                : sprintf('(SELECT %1$s FROM %2$s WHERE %1$s = ?)', $table[1], $table[0]);
        }

        return '('.implode(', ', $placeholders).')';
    }

    /**
     * @param non-empty-list<list<?string>> $rows
     */
    private function insert(string $template, array $rows): void
    {
        $this->connection->executeStatement(
            'INSERT INTO '.self::TABLE.' ('.implode(', ', self::COLUMNS).') VALUES '
                .implode(', ', array_fill(0, count($rows), $template)),
            array_merge(...$rows),
        );
    }
}
