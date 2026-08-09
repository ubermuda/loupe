<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RequestDataExportHandler
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RequestDataExportCommand $command): DataExport
    {
        if (null !== $this->dataExports->findOnePendingByUser($command->user)) {
            throw new DomainErrors(['export' => 'account.settings.export.error.already_pending']);
        }

        try {
            // The async transport shares $em's connection, so persist+flush and
            // dispatch commit atomically. Without it a dispatch failure leaves a
            // Pending row with no message, and the partial unique index makes
            // every retry report "already pending" forever — nothing clears it,
            // since only Ready exports expire.
            $export = $this->em->wrapInTransaction(function () use ($command): DataExport {
                $export = new DataExport($command->user);
                $this->em->persist($export);
                $this->em->flush();

                $id = $export->id ?? throw new \LogicException('flushed export always has an id');
                $this->bus->dispatch(new GenerateDataExportMessage((string) $id));

                return $export;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won the race between the pre-check above
            // and this flush — the partial unique index on data_exports
            // (one pending row per user) is the real guard; this turns its
            // violation into the same domain error instead of a 500.
            throw new DomainErrors(['export' => 'account.settings.export.error.already_pending']);
        }

        $this->logger->info('account.data_export.requested', ['id' => (string) $export->id]);

        return $export;
    }
}
