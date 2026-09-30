<?php

declare(strict_types=1);

namespace App\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lets a module add its own rows to `app:dev:seed`, which may not name the
 * module. An implementation is dev-only and must be idempotent, because the
 * command runs on every worktree entry.
 */
#[AutoconfigureTag('app.dev_data_seeder')]
interface DevDataSeederInterface
{
    public function seed(User $admin): void;
}
