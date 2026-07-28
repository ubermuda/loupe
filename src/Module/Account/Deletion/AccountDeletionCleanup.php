<?php

declare(strict_types=1);

namespace App\Module\Account\Deletion;

/**
 * Collects export-storage cleanup a purger needs deferred until after the
 * deletion transaction commits. A rolled-back transaction must leave a
 * still-existing row's archive untouched, so a purger must never delete
 * directly from inside purge() — it schedules the storage key here instead,
 * and DeleteAccountHandler processes the list only once the transaction has
 * durably committed.
 */
final class AccountDeletionCleanup
{
    /** @var list<string> */
    private array $archiveKeys = [];

    public function scheduleArchiveDeletion(string $key): void
    {
        $this->archiveKeys[] = $key;
    }

    /** @return list<string> */
    public function archivesToDelete(): array
    {
        return $this->archiveKeys;
    }
}
