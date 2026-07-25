<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
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

        $export = new DataExport($command->user);
        $this->em->persist($export);
        $this->em->flush();

        $id = $export->id ?? throw new \LogicException('flushed export always has an id');
        $this->bus->dispatch(new GenerateDataExportMessage((string) $id));
        $this->logger->info('account.data_export.requested', ['id' => (string) $id]);

        return $export;
    }
}
