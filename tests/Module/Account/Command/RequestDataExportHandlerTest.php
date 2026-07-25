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
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class RequestDataExportHandlerTest extends TestCase
{
    public function test_requests_an_export_and_dispatches_the_generate_message(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');

        /** @var DataExportRepository&Stub $dataExports */
        $dataExports = $this->createStub(DataExportRepository::class);
        $dataExports->method('findOnePendingByUser')->willReturn(null);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->createMock(EntityManagerInterface::class);
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

        $handler = new RequestDataExportHandler($dataExports, $em, $bus, new NullLogger());

        $export = $handler(new RequestDataExportCommand($user));

        self::assertSame($user, $export->user);
    }

    public function test_a_pending_export_is_rejected(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
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

        $handler = new RequestDataExportHandler($dataExports, $em, $bus, new NullLogger());

        try {
            $handler(new RequestDataExportCommand($user));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['export' => 'account.settings.export.error.already_pending'], $e->errors);
        }
    }

    public function test_a_concurrent_request_surfaces_the_domain_error_not_a_500(): void
    {
        // Both requests pass the pre-check (no pending row exists yet); one of
        // them then loses the race at flush() against the partial unique
        // index on data_exports(user_id) WHERE status = 'pending'.
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');

        /** @var DataExportRepository&Stub $dataExports */
        $dataExports = $this->createStub(DataExportRepository::class);
        $dataExports->method('findOnePendingByUser')->willReturn(null);

        /** @var EntityManagerInterface&Stub $em */
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(
            new UniqueConstraintViolationException(
                PdoDriverException::new(new \PDOException('duplicate key value violates unique constraint')),
                null,
            ),
        );

        /** @var MessageBusInterface&MockObject $bus */
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RequestDataExportHandler($dataExports, $em, $bus, new NullLogger());

        try {
            $handler(new RequestDataExportCommand($user));
            self::fail('Expected DomainErrors to be thrown.');
        } catch (DomainErrors $e) {
            self::assertSame(['export' => 'account.settings.export.error.already_pending'], $e->errors);
        }
    }
}
