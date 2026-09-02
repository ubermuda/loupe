<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\ProcessDataExportCommand;
use App\Module\Account\Command\ProcessDataExportHandler;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Entity\User;
use App\Module\Account\Export\DataExportArchiveBuilder;
use App\Module\Account\Repository\DataExportRepository;
use App\Module\Account\Service\DataExportEmailSender;
use App\Module\Account\Service\ExpiredExportPurger;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ProcessDataExportHandlerTest extends TestCase
{
    private RecordingAuditor $audit;
    private RecordingLogger $directLogger;

    private function persistedExport(): DataExport
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $export = new DataExport($user);
        $ref = new \ReflectionProperty(DataExport::class, 'id');
        $ref->setValue($export, Uuid::v7());

        return $export;
    }

    private function downloadTokenHashOf(DataExport $export): ?string
    {
        $ref = new \ReflectionProperty(DataExport::class, 'downloadTokenHash');

        /** @var ?string $value */
        $value = $ref->getValue($export);

        return $value;
    }

    public function test_happy_path_builds_completes_emails_and_purges(): void
    {
        $export = $this->persistedExport();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var DataExportArchiveBuilder&MockObject $builder */
        $builder = $this->createMock(DataExportArchiveBuilder::class);
        $builder->expects(self::once())->method('build')->with($export->user, $export->id);

        /** @var DataExportEmailSender&MockObject $emailSender */
        $emailSender = $this->createMock(DataExportEmailSender::class);
        $emailSender->expects(self::once())->method('send')->with($export, self::callback(is_string(...)));

        /** @var ExpiredExportPurger&MockObject $purger */
        $purger = $this->createMock(ExpiredExportPurger::class);
        $purger->expects(self::once())->method('purge');

        $em = $this->createStub(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->directLogger = new RecordingLogger();
        $handler = new ProcessDataExportHandler($exports, $builder, $emailSender, $purger, $em, $this->directLogger, $this->audit->auditor);

        $handler(new ProcessDataExportCommand((string) $export->id));

        self::assertSame(DataExportStatus::Ready, $export->status);

        $record = $this->audit->record('account.data_export_completed');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame(['id' => (string) $export->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('data_export', $record->subject->type);
        self::assertSame((string) $export->id, $record->subject->id);

        self::assertSame(['account.data_export_completed'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
        DirectLogging::assertOperationNotLoggedBy($this->audit, $this->directLogger, 'account.data_export_completed');
    }

    /**
     * Neither has an actor whose action it records, so both stay diagnostics
     * on the logger this class keeps beside the Auditor.
     */
    public function test_the_skip_and_purge_failure_branches_stay_diagnostics(): void
    {
        $export = $this->persistedExport();
        $export->fail();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        $audit = new RecordingAuditor(new NullAuditActorProvider());
        $logger = new RecordingLogger();
        $handler = new ProcessDataExportHandler(
            $exports,
            $this->createStub(DataExportArchiveBuilder::class),
            $this->createStub(DataExportEmailSender::class),
            $this->createStub(ExpiredExportPurger::class),
            $this->createStub(EntityManagerInterface::class),
            $logger,
            $audit->auditor,
        );

        $handler(new ProcessDataExportCommand((string) $export->id));

        self::assertSame([], $audit->operations());
        self::assertSame(
            ['account.data_export_skipped'],
            array_map(static fn (array $record): string => $record['message'], $logger->records),
        );
    }

    public function test_a_failing_purge_does_not_fail_the_export(): void
    {
        $export = $this->persistedExport();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var DataExportArchiveBuilder&MockObject $builder */
        $builder = $this->createMock(DataExportArchiveBuilder::class);
        $builder->expects(self::once())->method('build');

        /** @var DataExportEmailSender&MockObject $emailSender */
        $emailSender = $this->createMock(DataExportEmailSender::class);
        $emailSender->expects(self::once())->method('send');

        /** @var ExpiredExportPurger&MockObject $purger */
        $purger = $this->createMock(ExpiredExportPurger::class);
        $purger->expects(self::once())->method('purge')->willThrowException(new \RuntimeException('purge boom'));

        $em = $this->createStub(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->directLogger = new RecordingLogger();
        $handler = new ProcessDataExportHandler($exports, $builder, $emailSender, $purger, $em, $this->directLogger, $this->audit->auditor);

        $handler(new ProcessDataExportCommand((string) $export->id));

        self::assertSame(DataExportStatus::Ready, $export->status);
    }

    public function test_missing_export_does_nothing(): void
    {
        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn(null);

        /** @var DataExportArchiveBuilder&MockObject $builder */
        $builder = $this->createMock(DataExportArchiveBuilder::class);
        $builder->expects(self::never())->method('build');

        /** @var DataExportEmailSender&MockObject $emailSender */
        $emailSender = $this->createMock(DataExportEmailSender::class);
        $emailSender->expects(self::never())->method('send');

        /** @var ExpiredExportPurger&MockObject $purger */
        $purger = $this->createMock(ExpiredExportPurger::class);
        $purger->expects(self::never())->method('purge');

        $em = $this->createStub(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->directLogger = new RecordingLogger();
        $handler = new ProcessDataExportHandler($exports, $builder, $emailSender, $purger, $em, $this->directLogger, $this->audit->auditor);

        $handler(new ProcessDataExportCommand((string) Uuid::v7()));
    }

    public function test_failed_export_is_skipped(): void
    {
        $export = $this->persistedExport();
        $export->fail();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var DataExportArchiveBuilder&MockObject $builder */
        $builder = $this->createMock(DataExportArchiveBuilder::class);
        $builder->expects(self::never())->method('build');

        /** @var DataExportEmailSender&MockObject $emailSender */
        $emailSender = $this->createMock(DataExportEmailSender::class);
        $emailSender->expects(self::never())->method('send');

        /** @var ExpiredExportPurger&MockObject $purger */
        $purger = $this->createMock(ExpiredExportPurger::class);
        $purger->expects(self::never())->method('purge');

        $em = $this->createStub(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->directLogger = new RecordingLogger();
        $handler = new ProcessDataExportHandler($exports, $builder, $emailSender, $purger, $em, $this->directLogger, $this->audit->auditor);

        $handler(new ProcessDataExportCommand((string) $export->id));
    }

    public function test_ready_export_is_reprocessed_on_redelivery(): void
    {
        $export = $this->persistedExport();
        $export->complete();
        $firstHash = $this->downloadTokenHashOf($export);

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var DataExportArchiveBuilder&MockObject $builder */
        $builder = $this->createMock(DataExportArchiveBuilder::class);
        $builder->expects(self::once())->method('build');

        /** @var DataExportEmailSender&MockObject $emailSender */
        $emailSender = $this->createMock(DataExportEmailSender::class);
        $emailSender->expects(self::once())->method('send');

        /** @var ExpiredExportPurger&MockObject $purger */
        $purger = $this->createMock(ExpiredExportPurger::class);
        $purger->expects(self::once())->method('purge');

        $em = $this->createStub(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->directLogger = new RecordingLogger();
        $handler = new ProcessDataExportHandler($exports, $builder, $emailSender, $purger, $em, $this->directLogger, $this->audit->auditor);

        $handler(new ProcessDataExportCommand((string) $export->id));

        self::assertSame(DataExportStatus::Ready, $export->status);
        self::assertNotSame($firstHash, $this->downloadTokenHashOf($export));
    }

    public function test_builder_failure_propagates_and_leaves_export_pending(): void
    {
        $export = $this->persistedExport();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var DataExportArchiveBuilder&MockObject $builder */
        $builder = $this->createMock(DataExportArchiveBuilder::class);
        $builder->expects(self::once())->method('build')->willThrowException(new \RuntimeException('disk full'));

        /** @var DataExportEmailSender&MockObject $emailSender */
        $emailSender = $this->createMock(DataExportEmailSender::class);
        $emailSender->expects(self::never())->method('send');

        /** @var ExpiredExportPurger&MockObject $purger */
        $purger = $this->createMock(ExpiredExportPurger::class);
        $purger->expects(self::never())->method('purge');

        $em = $this->createStub(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->directLogger = new RecordingLogger();
        $handler = new ProcessDataExportHandler($exports, $builder, $emailSender, $purger, $em, $this->directLogger, $this->audit->auditor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disk full');

        try {
            $handler(new ProcessDataExportCommand((string) $export->id));
        } finally {
            self::assertSame(DataExportStatus::Pending, $export->status);
        }
    }

    public function test_email_failure_after_ready_flush_propagates(): void
    {
        $export = $this->persistedExport();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var DataExportArchiveBuilder&MockObject $builder */
        $builder = $this->createMock(DataExportArchiveBuilder::class);
        $builder->expects(self::once())->method('build')->willReturn('/tmp/export.zip');

        /** @var DataExportEmailSender&MockObject $emailSender */
        $emailSender = $this->createMock(DataExportEmailSender::class);
        $emailSender->expects(self::once())->method('send')->willThrowException(new \RuntimeException('smtp down'));

        /** @var ExpiredExportPurger&MockObject $purger */
        $purger = $this->createMock(ExpiredExportPurger::class);
        $purger->expects(self::never())->method('purge');

        $em = $this->createStub(EntityManagerInterface::class);
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
        $this->directLogger = new RecordingLogger();
        $handler = new ProcessDataExportHandler($exports, $builder, $emailSender, $purger, $em, $this->directLogger, $this->audit->auditor);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('smtp down');

        try {
            $handler(new ProcessDataExportCommand((string) $export->id));
        } finally {
            // The Ready flush already happened before the send — a redelivery
            // reprocesses this export (see test_ready_export_is_reprocessed_on_redelivery).
            self::assertSame(DataExportStatus::Ready, $export->status);
        }
    }
}
