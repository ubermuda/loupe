<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\EventListener;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Entity\User;
use App\Module\Account\EventListener\MarkExportFailedOnFinalFailure;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

final class MarkExportFailedOnFinalFailureTest extends TestCase
{
    private function persistedExport(): DataExport
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $export = new DataExport($user);
        $ref = new \ReflectionProperty(DataExport::class, 'id');
        $ref->setValue($export, Uuid::v7());

        return $export;
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

        $listener = new MarkExportFailedOnFinalFailure($exports, $em, new NullLogger());

        $event = new WorkerMessageFailedEvent(
            new Envelope(new GenerateDataExportMessage((string) $export->id)),
            'async',
            new \RuntimeException('boom'),
        );

        $listener($event);

        self::assertSame(DataExportStatus::Failed, $export->status);
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

        $listener = new MarkExportFailedOnFinalFailure($exports, $em, new NullLogger());

        $event = new WorkerMessageFailedEvent(
            new Envelope(new GenerateDataExportMessage((string) $export->id)),
            'async',
            new \RuntimeException('boom'),
        );
        $event->setForRetry();

        $listener($event);

        self::assertSame(DataExportStatus::Pending, $export->status);
    }

    public function test_an_unrelated_message_is_ignored(): void
    {
        /** @var DataExportRepository&Stub $exports */
        $exports = $this->createStub(DataExportRepository::class);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $listener = new MarkExportFailedOnFinalFailure($exports, $em, new NullLogger());

        $event = new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            'async',
            new \RuntimeException('boom'),
        );

        $listener($event);
    }
}
