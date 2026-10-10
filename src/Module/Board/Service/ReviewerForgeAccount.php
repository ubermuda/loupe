<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;

/** What the board knows of a reviewer's account on the forge. */
interface ReviewerForgeAccount
{
    /** `none` while the reviewer has no connected account. */
    public function stateOf(User $reviewer): string;

    /** The forge's id of the reviewer's account, to tell the reviewer's own pull requests apart. */
    public function forgeUserIdOf(User $reviewer): ?string;
}
