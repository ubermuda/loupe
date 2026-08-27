<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;

final readonly class ProfileExporter implements UserDataExporterInterface
{
    #[\Override]
    public function filename(): string
    {
        return 'profile.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        yield 'fullName' => $user->fullName;
        yield 'email' => $user->email;
        yield 'createdAt' => $user->createdAt->format(\DateTimeInterface::ATOM);
        yield 'emailVerifiedAt' => $user->emailVerifiedAt?->format(\DateTimeInterface::ATOM);
    }
}
