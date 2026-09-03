<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use Ubermuda\AuditBundle\AuditSubject;
use Ubermuda\AuditBundle\Repository\AuditLogRepository;

/**
 * Exports what the account did and what was done to it: the rows it is the
 * actor of, plus the rows that name it as the subject.
 *
 * A subject row was written by somebody else and carries that person's name in
 * actor_label, so the exported shape drops the label. What stays of the writer
 * is nothing: no other exported field names an actor.
 */
final readonly class AuditLogExporter implements UserDataExporterInterface
{
    /** The subject type every record about an account uses. */
    private const string USER_SUBJECT_TYPE = 'user';

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
        $subject = new AuditSubject(
            self::USER_SUBJECT_TYPE,
            (string) ($user->id ?? throw new \LogicException('a persisted user always has an id')),
        );

        foreach ($this->auditLogs->streamByActorOrSubject($user, $subject) as $row) {
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
