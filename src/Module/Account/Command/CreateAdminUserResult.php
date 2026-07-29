<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;

/** What CreateAdminUserHandler had to change to reach "this account is a verified administrator". */
final readonly class CreateAdminUserResult
{
    public function __construct(
        public User $user,
        /** False when the email already had an account, which is then promoted and verified in place. */
        public bool $created,
        /** True only when ROLE_ADMIN was missing and got added. */
        public bool $promoted,
        /** True only when the email was unverified and got marked verified. */
        public bool $verified,
    ) {
    }
}
