<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/** Reads the stored connection of a user on one forge. It calls no forge API. A forge module implements it. */
#[AutoconfigureTag('app.forge_user_account_reader')]
interface ForgeUserAccountReader
{
    /** @param string $forge the forge's slug, such as `github` */
    public function supports(string $forge): bool;

    public function accountOf(Uuid $userId): ForgeUserAccount;
}
