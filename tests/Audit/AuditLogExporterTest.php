<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Audit\AuditLogExporter;
use App\Module\Account\Entity\User;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\Entity\AuditLog;
use App\Module\Audit\Repository\AuditLogRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AuditLogExporterTest extends TestCase
{
    public function test_exports_flat_record_rows(): void
    {
        $actor = new User('Alice A', 'alice@example.com', 'x');
        $record = new AuditLog(
            operation: 'review.comment.addressed',
            outcome: AuditOutcome::Refused,
            category: 'domain',
            channel: 'mcp',
            occurredAt: new \DateTimeImmutable('2026-08-01 09:30:00'),
            context: ['commentId' => 'c-1', 'result' => 'AlreadyResolved'],
            actor: $actor,
            actorLabel: 'Alice A',
            subjectType: 'comment',
            subjectId: 'c-1',
        );

        $rows = iterator_to_array(new AuditLogExporter($this->repository([$record]))->export($actor));

        self::assertCount(1, $rows);
        self::assertSame('review.comment.addressed', $rows[0]['operation']);
        self::assertSame('refused', $rows[0]['outcome']);
        self::assertSame('domain', $rows[0]['category']);
        self::assertSame('mcp', $rows[0]['channel']);
        self::assertSame('comment', $rows[0]['subjectType']);
        self::assertSame('c-1', $rows[0]['subjectId']);
        self::assertSame(['commentId' => 'c-1', 'result' => 'AlreadyResolved'], $rows[0]['context']);
        self::assertArrayHasKey('occurredAt', $rows[0]);
    }

    /** A row the user did not write names somebody else, so the label stays out. */
    public function test_a_row_carries_no_actor_label(): void
    {
        $actor = new User('Alice A', 'alice@example.com', 'x');
        $record = new AuditLog(
            operation: 'account.user.suspended',
            outcome: AuditOutcome::Success,
            category: 'domain',
            channel: 'session',
            actor: $actor,
            actorLabel: 'Alice A',
        );

        $rows = iterator_to_array(new AuditLogExporter($this->repository([$record]))->export($actor));

        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('actorLabel', $rows[0]);
        self::assertSame([], array_filter(
            $rows[0],
            static fn (mixed $value): bool => \is_string($value) && str_contains($value, 'Alice A'),
        ));
    }

    public function test_the_file_is_named_for_the_table(): void
    {
        self::assertSame('audit_log.json', new AuditLogExporter($this->repository([]))->filename());
    }

    /**
     * @param list<AuditLog> $records
     */
    private function repository(array $records): AuditLogRepository
    {
        /** @var AuditLogRepository&Stub $repo */
        $repo = $this->createStub(AuditLogRepository::class);
        $repo->method('streamByActor')->willReturn($records);

        return $repo;
    }
}
