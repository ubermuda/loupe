<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class RequestDataExportHandler
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private Auditor $auditor,
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

        // After the commit, never inside it: the sink drains outside the
        // business transaction, so a record written in there survives a rollback.
        $this->auditor->record(
            'account.data_export.requested',
            AuditOutcome::Success,
            ['id' => (string) $export->id],
            new AuditSubject('data_export', (string) $export->id),
        );

        return $export;
    }
}
