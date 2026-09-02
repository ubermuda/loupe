<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Audit\Repository\AuditLogRepository;

/**
 * Exports what the account did, which is the rows it is the actor of. A row
 * that only names it as a subject belongs to whoever wrote it, and carries that
 * person's name in actor_label.
 */
final readonly class AuditLogExporter implements UserDataExporterInterface
{
    public function __construct(
        private AuditLogRepository $auditLogs,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'audit_log.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->auditLogs->streamByActor($user) as $row) {
            yield [
                'occurredAt' => $row['occurredAt']->format(\DateTimeInterface::ATOM),
                'operation' => $row['operation'],
                'outcome' => $row['outcome']->value,
                'category' => $row['category'],
                'channel' => $row['channel'],
                'subjectType' => $row['subjectType'],
                'subjectId' => $row['subjectId'],
                'context' => $row['context'],
            ];
        }
    }
}
