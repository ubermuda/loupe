<?php

declare(strict_types=1);

namespace App\Module\Account\Deletion;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Work that has to happen before the first purger, because a purger destroys
 * the state it depends on.
 *
 * ProjectAccountPurger holds the lowest deletionOrder() and must keep it, so a
 * module whose rows stop being identifiable once it has run cannot solve that
 * with an ordering. Deleting a project deletes its bound API tokens, and every
 * foreign key onto a token is ON DELETE SET NULL — a row pointing at one is
 * silently anonymised rather than removed, and no later purger can tell whose
 * it was.
 *
 * A preparer runs inside the deletion transaction, before any purge(), so it
 * still sees the links intact. It is a separate phase rather than another
 * purger slot, which keeps "ProjectAccountPurger runs first" true.
 */
#[AutoconfigureTag('app.account_deletion_preparer')]
interface AccountDeletionPreparerInterface
{
    /** Runs before every purge(), while the rows an early purger will destroy still exist. */
    public function prepare(User $user, AccountDeletionCleanup $cleanup): void;
}
