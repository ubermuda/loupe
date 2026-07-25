<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\DataExport\UserDataExporterInterface;
use App\Module\Account\Entity\User;

final readonly class ProfileExporter implements UserDataExporterInterface
{
    #[\Override]
    public function filename(): string
    {
        return 'profile.json';
    }

    #[\Override]
    public function export(User $user): array
    {
        return [
            'username' => $user->username,
            'fullName' => $user->fullName,
            'email' => $user->email,
            'createdAt' => $user->createdAt->format(\DateTimeInterface::ATOM),
            'emailVerifiedAt' => $user->emailVerifiedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
