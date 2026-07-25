<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class MarkExportFailedOnFinalFailure
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof GenerateDataExportMessage || $event->willRetry()) {
            return;
        }

        $export = $this->dataExports->find(Uuid::fromString($message->dataExportId));
        if (null === $export || DataExportStatus::Failed === $export->status) {
            return;
        }

        $export->fail();
        $this->em->flush();
        $this->logger->warning('account.data_export.failed', ['id' => $message->dataExportId]);
    }
}
