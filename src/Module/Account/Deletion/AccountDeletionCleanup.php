<?php

declare(strict_types=1);

namespace App\Module\Account\Deletion;

/**
 * Collects filesystem cleanup a purger needs deferred until after the
 * deletion transaction commits. A rolled-back transaction must leave a
 * still-existing row's file untouched, so a purger must never unlink
 * directly from inside purge() — it schedules the path here instead, and
 * DeleteAccountHandler processes the list only once the transaction has
 * durably committed.
 */
final class AccountDeletionCleanup
{
    /** @var list<string> */
    private array $filesToUnlink = [];

    public function scheduleFileUnlink(string $path): void
    {
        $this->filesToUnlink[] = $path;
    }

    /** @return list<string> */
    public function filesToUnlink(): array
    {
        return $this->filesToUnlink;
    }
}
