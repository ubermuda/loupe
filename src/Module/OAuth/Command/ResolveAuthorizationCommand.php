<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

final readonly class ResolveAuthorizationCommand
{
    public function __construct(
        public AuthorizationRequestInterface $authorizationRequest,
        /** @var non-empty-list<ApiTokenScope> */
        public array $scopes,
        public User $user,
        public bool $approved,
        public ?string $projectId = null,
    ) {
    }
}
