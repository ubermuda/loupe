<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * No module stores a forge account for a user yet, so every reviewer reads as
 * unconnected. A source of connections replaces this class.
 */
#[AsAlias(ReviewerForgeAccount::class)]
final readonly class UnconnectedReviewerForgeAccount implements ReviewerForgeAccount
{
    public const string NONE = 'none';

    #[\Override]
    public function stateOf(User $reviewer): string
    {
        return self::NONE;
    }

    #[\Override]
    public function forgeUserIdOf(User $reviewer): ?string
    {
        return null;
    }
}
