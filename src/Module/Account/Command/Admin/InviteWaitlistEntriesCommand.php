<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

final readonly class InviteWaitlistEntriesCommand
{
    public function __construct(
        /** @var list<string> UUID strings from the admin form */
        public array $entryIds,
    ) {
    }
}
