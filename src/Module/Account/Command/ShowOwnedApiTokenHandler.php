<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Repository\ApiTokenRepository;

final readonly class ShowOwnedApiTokenHandler
{
    public function __construct(
        private ApiTokenRepository $apiTokens,
    ) {
    }

    public function __invoke(ShowOwnedApiTokenCommand $command): ShowOwnedApiTokenView
    {
        $token = $this->apiTokens->find($command->tokenId);
        $ownedByCaller = $token instanceof ApiToken
            && null !== $token->owner->id
            && $token->owner->id->equals($command->owner->id);

        return new ShowOwnedApiTokenView($ownedByCaller ? $token : null);
    }
}
