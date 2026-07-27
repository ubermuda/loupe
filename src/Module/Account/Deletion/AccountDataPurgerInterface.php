<?php

declare(strict_types=1);

namespace App\Module\Account\Deletion;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per module; each purges its own user-owned rows during
 * account deletion. Tagged + collected (and ordered) by DeleteAccountHandler,
 * mirroring UserDataExporterInterface's per-module export design — each
 * module owns its own purger, Account owns only the iteration.
 *
 * Symfony's tagged_iterator does not guarantee a stable iteration order, so
 * ordering is explicit via deletionOrder() rather than relying on service
 * registration order. The one real cross-purger constraint today:
 * ProjectAccountPurger is the only ORM-based purger, and it calls
 * EntityManager::clear() as it iterates the user's projects (a pre-existing
 * SiteReview identity-map staleness workaround) — it MUST run first (lowest
 * deletionOrder()), and every purger that runs after it must treat $user as
 * potentially detached: read $user->id as a scalar, never pass $user itself
 * into an ORM query.
 */
#[AutoconfigureTag('app.account_data_purger')]
interface AccountDataPurgerInterface
{
    /** Lower numbers run first. */
    public function deletionOrder(): int;

    /** Deletes every row this module owns that has a foreign key onto $user. */
    public function purge(User $user, AccountDeletionCleanup $cleanup): void;
}
