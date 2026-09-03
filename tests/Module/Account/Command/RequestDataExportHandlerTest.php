<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\RequestDataExportCommand;
use App\Module\Account\Command\RequestDataExportHandler;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class RequestDataExportHandlerTest extends TestCase
{
    public function test_requests_an_export_and_dispatches_the_generate_message(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');

        /** @var DataExportRepository&Stub $dataExports */
        $dataExports = $this->createStub(DataExportRepository::class);
        $dataExports->method('findOnePendingByUser')->willReturn(null);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        // Real Doctrine invokes the closure and returns its result; the mock
        // mirrors that instead of asserting real transactional behaviour,
        // which isn't unit-testable against a mocked EM (see project-backend's
        // note on wrapInTransaction()'s test limitations).
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $em->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(DataExport::class))
            ->willReturnCallback(static function (DataExport $export): void {
                // Doctrine assigns the id on persist/flush; the mocked EM
                // doesn't, so the id is set here to let the handler proceed
                // past its "flushed export always has an id" guard.
                new \ReflectionProperty(DataExport::class, 'id')->setValue($export, Uuid::v7());
            });
        $em->expects(self::once())->method('flush');

        /** @var MessageBusInterface&MockObject $bus */
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(GenerateDataExportMessage::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = new RequestDataExportHandler($dataExports, $em, $bus, $audit->auditor);

        $export = $handler(new RequestDataExportCommand($user));

        self::assertSame($user, $export->user);

        $record = $audit->record('account.data_export_requested');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['id' => (string) $export->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('data_export', $record->subject->type);
        self::assertSame((string) $export->id, $record->subject->id);

        self::assertSame(['account.data_export_requested'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(RequestDataExportHandler::class);
    }

    public function test_a_pending_export_is_rejected(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $pending = new DataExport($user);

        /** @var DataExportRepository&Stub $dataExports */
        $dataExports = $this->createStub(DataExportRepository::class);
        $dataExports->method('findOnePendingByUser')->willReturn($pending);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        /** @var MessageBusInterface&MockObject $bus */
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = new RequestDataExportHandler($dataExports, $em, $bus, $audit->auditor);

        try {
            $handler(new RequestDataExportCommand($user));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['export' => 'account.settings.export.error.already_pending'], $e->errors);
        }

        self::assertSame([], $audit->operations());
    }

    /**
     * The dispatch shares the transaction, so a failing one rolls the export
     * row back. A record written inside that closure would outlive the
     * rollback and state a request the database never kept.
     */
    public function test_a_dispatch_failure_rolls_the_request_back_and_records_nothing(): void
    {
        $user = new User('Alice A', 'alice@example.com', 'x');

        /** @var DataExportRepository&Stub $dataExports */
        $dataExports = $this->createStub(DataExportRepository::class);
        $dataExports->method('findOnePendingByUser')->willReturn(null);

        /** @var EntityManagerInterface&Stub $em */
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $export): void {
            new \ReflectionProperty(DataExport::class, 'id')->setValue($export, Uuid::v7());
        });
        // Runs the closure, then throws as a rollback does: whatever the closure
        // did to the database is undone by the time the caller sees this.
        $em->method('wrapInTransaction')->willReturnCallback(static function (callable $fn): never {
            try {
                $fn();
            } catch (\RuntimeException $e) {
                throw $e;
            }

            throw new \RuntimeException('the transaction rolled back');
        });

        /** @var MessageBusInterface&Stub $bus */
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('the transport is gone'));

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = new RequestDataExportHandler($dataExports, $em, $bus, $audit->auditor);

        try {
            $handler(new RequestDataExportCommand($user));
            self::fail('Expected the rollback to reach the caller.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $audit->operations());
    }

    public function test_a_concurrent_request_surfaces_the_domain_error_not_a_500(): void
    {
        // Both requests pass the pre-check (no pending row exists yet); one of
        // them then loses the race at flush() against the partial unique
        // index on data_exports(user_id) WHERE status = 'pending'.
        $user = new User('Alice A', 'alice@example.com', 'x');

        /** @var DataExportRepository&Stub $dataExports */
        $dataExports = $this->createStub(DataExportRepository::class);
        $dataExports->method('findOnePendingByUser')->willReturn(null);

        /** @var EntityManagerInterface&Stub $em */
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $fn) => $fn());
        $em->method('flush')->willThrowException(
            new UniqueConstraintViolationException(
                PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint')),
                null,
            ),
        );

        /** @var MessageBusInterface&MockObject $bus */
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $handler = new RequestDataExportHandler($dataExports, $em, $bus, $audit->auditor);

        try {
            $handler(new RequestDataExportCommand($user));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['export' => 'account.settings.export.error.already_pending'], $e->errors);
        }

        // The lost race rolls the transaction back, so a record here would
        // state a request the database never kept.
        self::assertSame([], $audit->operations());
    }
}
