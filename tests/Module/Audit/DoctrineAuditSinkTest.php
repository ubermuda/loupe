<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditEvent;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Audit\DoctrineAuditSink;
use App\Tests\Support\FakeAuditActor;
use App\Tests\Support\FakeAuditCredential;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * The statement-shape half of the sink. What it does to a real database — and
 * to a rolled-back transaction — is in App\Tests\Audit\AuditTrailPersistenceTest.
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

        $sink = new DoctrineAuditSink($failing);
        $sink->write($this->event());

        try {
            $sink->flush();
            self::fail('A drain failure must reach the caller, which is what reports it.');
        } catch (\RuntimeException) {
        }

        $sink->flush();

        self::assertSame(1, $attempts);
    }

    public function test_a_rejected_chunk_does_not_take_the_untried_chunks_with_it(): void
    {
        $rowsPerStatement = intdiv(65535, self::COLUMNS);
        $failing = $this->createStub(Connection::class);
        $attempts = 0;
        $failing->method('executeStatement')->willReturnCallback(
            static function () use (&$attempts): int {
                if (1 === ++$attempts) {
                    throw new \RuntimeException('one row in this chunk was rejected');
                }

                return 1;
            },
        );

        $sink = new DoctrineAuditSink($failing);
        for ($i = 0; $i < $rowsPerStatement + 1; ++$i) {
            $sink->write($this->event());
        }

        try {
            $sink->flush();
            self::fail('A drain failure must still reach the caller, which is what reports it.');
        } catch (\RuntimeException) {
        }

        self::assertSame(2, $attempts, 'The chunk behind the failing one must still be attempted.');
    }

    public function test_the_row_carries_the_event_in_the_declared_column_order(): void
    {
        $sink = $this->sink();
        $sink->write(new AuditEvent(
            'document.deleted',
            AuditOutcome::Refused,
            Auditor::CATEGORY_SECURITY,
            new FakeAuditActor('Riley Chen'),
            null,
            'mcp',
            new AuditSubject('document', 'doc-1'),
            ['documentId' => 'doc-1'],
            new \DateTimeImmutable('2026-08-29 12:00:00.123456'),
        ));
        $sink->flush();

        [, $operation, $outcome, $category, $channel, $actorId, $actorLabel, $credentialId, $subjectType, $subjectId, $context, $occurredAt] = $this->statements[0]['params'];

        self::assertSame('document.deleted', $operation);
        self::assertSame('refused', $outcome);
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

    public function test_the_identifier_columns_carry_the_identifiers_the_event_snapshotted(): void
    {
        $sink = $this->sink();
        $sink->write($this->event(
            actor: new FakeAuditActor('Riley Chen', 'user-1'),
            credential: new FakeAuditCredential('token-1'),
        ));
        $sink->flush();

        [, , , , , $actorId, , $credentialId] = $this->statements[0]['params'];

        self::assertSame('user-1', $actorId);
        self::assertSame('token-1', $credentialId);
    }

    /**
     * The row records what the event captured when it was built, not what its
     * objects say later: they are still live, and asking them again would date
     * the id differently from the label beside it.
     */
    public function test_the_row_follows_the_snapshot_rather_than_the_live_objects(): void
    {
        $actor = new FakeAuditActor('Riley Chen', 'user-1');
        $credential = new FakeAuditCredential('token-1');
        $event = $this->event(actor: $actor, credential: $credential);

        $actor->identifier = 'user-2';
        $credential->identifier = 'token-2';

        $sink = $this->sink();
        $sink->write($event);
        $sink->flush();

        [, , , , , $actorId, , $credentialId] = $this->statements[0]['params'];

        self::assertSame('user-1', $actorId);
        self::assertSame('token-1', $credentialId);
    }

    /**
     * An actor Doctrine has not assigned an id to yet says so, and the record is
     * still written: the trail keeps the label, which is the part a reader needs.
     */
    public function test_an_actor_with_no_identifier_still_gets_a_row(): void
    {
        $sink = $this->sink();
        $sink->write($this->event(actor: new FakeAuditActor('Riley Chen')));
        $sink->flush();

        self::assertCount(1, $this->statements);

        [, , , , , $actorId, $actorLabel] = $this->statements[0]['params'];

        self::assertNull($actorId);
        self::assertSame('Riley Chen', $actorLabel);
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
        return new DoctrineAuditSink($this->connection);
    }

    private function event(
        AuditOutcome $outcome = AuditOutcome::Success,
        ?FakeAuditActor $actor = null,
        ?FakeAuditCredential $credential = null,
    ): AuditEvent {
        return new AuditEvent(
            'document.deleted',
            $outcome,
            Auditor::CATEGORY_DOMAIN,
            $actor,
            $credential,
            'session',
            null,
            [],
            new \DateTimeImmutable('2026-08-29 12:00:00'),
        );
    }
}
