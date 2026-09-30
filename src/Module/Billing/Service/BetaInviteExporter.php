<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Billing\Repository\BetaInviteRepository;

/** The invite note is the admin's own record, so it stays out of the export. */
final readonly class BetaInviteExporter implements UserDataExporterInterface
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'beta_invite.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $redeemedAt = $this->betaInvites->findOneRedeemedBy($user)?->redeemedAt;
        if (null === $redeemedAt) {
            return;
        }

        yield 'betaTesterSince' => $redeemedAt->format(\DateTimeInterface::ATOM);
    }
}
