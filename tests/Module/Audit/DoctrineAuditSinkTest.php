<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditEvent;
use App\Module\Audit\AuditLevel;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditSubject;
use App\Module\Audit\DoctrineAuditSink;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The statement-shape half of the sink. What it does to a real database — and
 * to a rolled-back transaction — is in DoctrineAuditSinkPersistenceTest.
 */
final class DoctrineAuditSinkTest extends TestCase
{
    /** Columns per row, so a parameter count says how many rows a statement carried. */
    private const int COLUMNS = 12;

    private Connection&Stub $connection;

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $statements = [];

    protected function setUp(): void
    {
        $this->statements = [];
        $this->connection = $this->createStub(Connection::class);
        $this->connection->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params): int {
                $this->statements[] = ['sql' => $sql, 'params' => array_values($params)];

                return count($params);
            });
    }

    public function test_writing_issues_no_statement_until_the_drain(): void
    {
        $sink = $this->sink();
        $sink->write($this->event());
        $sink->write($this->event());

        self::assertSame([], $this->statements);

        $sink->flush();

        self::assertCount(1, $this->statements);
    }

    public function test_a_drain_of_many_events_is_a_single_insert(): void
    {
        $sink = $this->sink();
        for ($i = 0; $i < 50; ++$i) {
            $sink->write($this->event());
        }
        $sink->flush();

        self::assertCount(1, $this->statements);
        self::assertCount(50 * self::COLUMNS, $this->statements[0]['params']);
    }

    public function test_a_drain_larger_than_the_parameter_ceiling_is_chunked(): void
    {
        $rowsPerStatement = intdiv(65535, self::COLUMNS);

        $sink = $this->sink();
        for ($i = 0; $i < $rowsPerStatement + 1; ++$i) {
            $sink->write($this->event());
        }
        $sink->flush();

        self::assertCount(2, $this->statements);
        self::assertCount($rowsPerStatement * self::COLUMNS, $this->statements[0]['params']);
        self::assertCount(self::COLUMNS, $this->statements[1]['params']);
    }

    public function test_a_drained_buffer_is_not_written_twice(): void
    {
        $sink = $this->sink();
        $sink->write($this->event());
        $sink->flush();
        $sink->flush();

        self::assertCount(1, $this->statements);
    }

    public function test_reset_discards_what_was_buffered(): void
    {
        $sink = $this->sink();
        $sink->write($this->event());
        $sink->reset();
        $sink->flush();

        self::assertSame([], $this->statements);
    }

    public function test_a_failed_drain_drops_its_records_rather_than_retrying_them(): void
    {
        $failing = $this->createStub(Connection::class);
        $attempts = 0;
        $failing->method('executeStatement')->willReturnCallback(
            static function () use (&$attempts): int {
                ++$attempts;

                throw new \RuntimeException('the connection is gone');
            },
        );

        $sink = new DoctrineAuditSink($failing, $this->createStub(EntityManagerInterface::class), 'info');
        $sink->write($this->event());

        try {
            $sink->flush();
            self::fail('A drain failure must reach the caller, which is what reports it.');
        } catch (\RuntimeException) {
        }

        $sink->flush();

        self::assertSame(1, $attempts);
    }

    #[DataProvider('levelsAgainstAnInfoFloor')]
    public function test_the_configured_floor_decides_what_reaches_the_table(AuditLevel $level, bool $expectedToBeStored): void
    {
        $sink = $this->sink();
        $sink->write($this->event($level));
        $sink->flush();

        self::assertCount($expectedToBeStored ? 1 : 0, $this->statements);
    }

    /**
     * @return iterable<string, array{AuditLevel, bool}>
     */
    public static function levelsAgainstAnInfoFloor(): iterable
    {
        yield 'below the floor' => [AuditLevel::Debug, false];
        yield 'at the floor' => [AuditLevel::Info, true];
        yield 'above the floor' => [AuditLevel::Error, true];
    }

    public function test_the_row_carries_the_event_in_the_declared_column_order(): void
    {
        $sink = $this->sink();
        $sink->write(new AuditEvent(
            'document.deleted',
            AuditLevel::Warning,
            Auditor::CATEGORY_SECURITY,
            null,
            'Riley Chen',
            null,
            'mcp',
            new AuditSubject('document', 'doc-1'),
            ['documentId' => 'doc-1'],
            new \DateTimeImmutable('2026-08-29 12:00:00.123456'),
        ));
        $sink->flush();

        [, $operation, $level, $category, $channel, $actorId, $actorLabel, $credentialId, $subjectType, $subjectId, $context, $occurredAt] = $this->statements[0]['params'];

        self::assertSame('document.deleted', $operation);
        self::assertSame('warning', $level);
        self::assertSame(Auditor::CATEGORY_SECURITY, $category);
        self::assertSame('mcp', $channel);
        self::assertNull($actorId);
        self::assertSame('Riley Chen', $actorLabel);
        self::assertNull($credentialId);
        self::assertSame('document', $subjectType);
        self::assertSame('doc-1', $subjectId);
        self::assertSame('{"documentId":"doc-1"}', $context);
        self::assertSame('2026-08-29 12:00:00.123456', $occurredAt);
    }

    /** So `context->>'key'` keeps working on a row that happened to carry nothing. */
    public function test_an_empty_context_is_stored_as_an_object_rather_than_an_array(): void
    {
        $sink = $this->sink();
        $sink->write($this->event());
        $sink->flush();

        self::assertSame('{}', $this->statements[0]['params'][10]);
    }

    private function sink(): DoctrineAuditSink
    {
        return new DoctrineAuditSink($this->connection, $this->createStub(EntityManagerInterface::class), 'info');
    }

    private function event(AuditLevel $level = AuditLevel::Info): AuditEvent
    {
        return new AuditEvent(
            'document.deleted',
            $level,
            Auditor::CATEGORY_DOMAIN,
            null,
            null,
            null,
            'session',
            null,
            [],
            new \DateTimeImmutable('2026-08-29 12:00:00'),
        );
    }
}
