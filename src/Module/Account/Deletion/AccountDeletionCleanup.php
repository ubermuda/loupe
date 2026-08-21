<?php

declare(strict_types=1);

namespace App\Module\Account\Deletion;

/**
 * Collects the work a purger cannot do inline, so AccountPurger can run it at
 * the right moment without knowing which module asked.
 *
 * Storage deletions wait until after the transaction commits: a rolled-back
 * deletion must leave a still-existing row's archive untouched, so a purger
 * must never delete from inside purge(). Messages go out inside it instead,
 * because the async transport shares the deletion's own connection.
 */
final class AccountDeletionCleanup
{
    /** @var list<string> */
    private array $archiveKeys = [];

    /** @var list<object> */
    private array $messages = [];

    public function scheduleArchiveDeletion(string $key): void
    {
        $this->archiveKeys[] = $key;
    }

    /** @return list<string> */
    public function archivesToDelete(): array
    {
        return $this->archiveKeys;
    }

    /**
     * Records a message to dispatch inside the deletion transaction, so a
     * purger can hand follow-up work to its own module without the handler
     * knowing the message type. Inside, not after: the async transport shares
     * this connection, so the row commits or rolls back with the deletion.
     */
    public function scheduleMessage(object $message): void
    {
        $this->messages[] = $message;
    }

    /** @return list<object> */
    public function messagesToDispatch(): array
    {
        return $this->messages;
    }
}
