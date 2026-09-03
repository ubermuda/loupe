<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\EventListener;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Entity\User;
use App\Module\Account\EventListener\MarkExportFailedOnFinalFailure;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\NullAuditActorProvider;

final class MarkExportFailedOnFinalFailureTest extends TestCase
{
    /** @var list<string> */
    private array $rootsToRemove = [];

    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->rootsToRemove as $root) {
            foreach (glob($root.'/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }

    private function persistedExport(): DataExport
    {
        $user = new User('Alice A', 'alice@example.com', 'x');
        $export = new DataExport($user);
        $ref = new \ReflectionProperty(DataExport::class, 'id');
        $ref->setValue($export, Uuid::v7());

        return $export;
    }

    private function localStorage(): Filesystem
    {
        $root = sys_get_temp_dir().'/loupe-failed-export-test-'.bin2hex(random_bytes(4));
        $this->rootsToRemove[] = $root;

        return new Filesystem(new LocalFilesystemAdapter($root));
    }

    public function test_final_failure_marks_the_export_failed(): void
    {
        $export = $this->persistedExport();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $listener = new MarkExportFailedOnFinalFailure($exports, $em, $this->audit->auditor, $this->localStorage());

        $event = new WorkerMessageFailedEvent(
            new Envelope(new GenerateDataExportMessage((string) $export->id)),
            'async',
            new \RuntimeException('boom'),
        );

        $listener($event);

        self::assertSame(DataExportStatus::Failed, $export->status);

        $record = $this->audit->record('account.data_export_failed');
        self::assertSame(AuditOutcome::Failed, $record->outcome);
        self::assertSame(['id' => (string) $export->id], $record->context);
        self::assertNotNull($record->subject);
        self::assertSame('data_export', $record->subject->type);
        self::assertSame((string) $export->id, $record->subject->id);
        self::assertSame(['account.data_export_failed'], $this->audit->operations());
    }

    /**
     * The archive outlives the row that pointed at it, so the trail says the
     * unlink broke as well as the export.
     */
    public function test_an_archive_that_could_not_be_unlinked_records_its_own_failure(): void
    {
        $export = $this->persistedExport();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        $storage = $this->createMock(Filesystem::class);
        $storage->expects($this->once())->method('delete')
            ->willThrowException(new UnableToDeleteFile('bucket unreachable'));

        $listener = new MarkExportFailedOnFinalFailure(
            $exports,
            $this->createStub(EntityManagerInterface::class),
            $this->audit->auditor,
            $storage,
        );

        $listener(new WorkerMessageFailedEvent(
            new Envelope(new GenerateDataExportMessage((string) $export->id)),
            'async',
            new \RuntimeException('boom'),
        ));

        self::assertSame(
            ['account.data_export_failed_archive_unlink_failed', 'account.data_export_failed'],
            $this->audit->operations(),
        );
        self::assertSame(
            AuditOutcome::Failed,
            $this->audit->record('account.data_export_failed_archive_unlink_failed')->outcome,
        );
    }

    public function test_the_listener_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(MarkExportFailedOnFinalFailure::class);
    }

    public function test_final_failure_deletes_an_archive_that_was_already_written(): void
    {
        $export = $this->persistedExport();
        $exportId = $export->id;
        self::assertNotNull($exportId);

        $storage = $this->localStorage();
        $key = DataExport::computeArchiveKey($exportId);
        $storage->write($key, 'partial archive bytes');

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var EntityManagerInterface&Stub $em */
        $em = $this->createStub(EntityManagerInterface::class);

        $listener = new MarkExportFailedOnFinalFailure($exports, $em, $this->audit->auditor, $storage);

        $event = new WorkerMessageFailedEvent(
            new Envelope(new GenerateDataExportMessage((string) $export->id)),
            'async',
            new \RuntimeException('boom'),
        );

        $listener($event);

        self::assertFalse($storage->fileExists($key));
    }

    public function test_a_failure_that_will_still_retry_is_left_untouched(): void
    {
        $export = $this->persistedExport();

        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);
        $exports->method('find')->willReturn($export);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $listener = new MarkExportFailedOnFinalFailure($exports, $em, $this->audit->auditor, $this->localStorage());

        $event = new WorkerMessageFailedEvent(
            new Envelope(new GenerateDataExportMessage((string) $export->id)),
            'async',
            new \RuntimeException('boom'),
        );
        $event->setForRetry();

        $listener($event);

        self::assertSame(DataExportStatus::Pending, $export->status);
        self::assertSame([], $this->audit->operations());
    }

    public function test_an_unrelated_message_is_ignored(): void
    {
        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $listener = new MarkExportFailedOnFinalFailure($exports, $em, $this->audit->auditor, $this->localStorage());

        $event = new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'async',
            new \RuntimeException('boom'),
        );

        $listener($event);

        self::assertSame([], $this->audit->operations());
    }
}
