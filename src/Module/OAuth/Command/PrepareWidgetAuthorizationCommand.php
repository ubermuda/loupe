<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\Account\Entity\User;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

final readonly class PrepareWidgetAuthorizationCommand
{
    public function __construct(
        public AuthorizationRequestInterface $authorizationRequest,
        public User $user,
        /** The project id the widget's embed names, as sent. */
        public string $projectId,
        /** The origin of the page the widget runs on, as sent. */
        public string $origin,
    ) {
    }
}
