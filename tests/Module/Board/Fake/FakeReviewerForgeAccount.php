<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Fake;

use App\Module\Account\Entity\User;
use App\Module\Board\Service\ReviewerForgeAccount;

final class FakeReviewerForgeAccount implements ReviewerForgeAccount
{
    public function __construct(
        public string $state = 'connected',
        public ?string $forgeUserId = '4242',
    ) {
    }

    #[\Override]
    public function stateOf(User $reviewer): string
    {
        return $this->state;
    }

    #[\Override]
    public function forgeUserIdOf(User $reviewer): ?string
    {
        return $this->forgeUserId;
    }
}
