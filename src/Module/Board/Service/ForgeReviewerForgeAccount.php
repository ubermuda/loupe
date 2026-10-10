<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Service\ForgeUserAccount;
use App\Module\Forge\Service\ForgeUserAccountReaders;
use App\Module\Forge\Service\ForgeUserConnectionState;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(ReviewerForgeAccount::class)]
final readonly class ForgeReviewerForgeAccount implements ReviewerForgeAccount
{
    public function __construct(
        private ForgeUserAccountReaders $readers,
    ) {
    }

    #[\Override]
    public function stateOf(User $reviewer): string
    {
        return $this->accountOf($reviewer)->state->value;
    }

    #[\Override]
    public function forgeUserIdOf(User $reviewer): ?string
    {
        return $this->accountOf($reviewer)->forgeUserId;
    }

    private function accountOf(User $reviewer): ForgeUserAccount
    {
        return $this->readers->for(Forge::GitHub->value)?->accountOf($reviewer->id ?? throw new \LogicException('A signed-in reviewer is stored'))
            ?? new ForgeUserAccount(ForgeUserConnectionState::None);
    }
}
