<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

enum SocialProvider: string
{
    case Google = 'google';
    case Github = 'github';

    public function label(): string
    {
        return 'account.social.provider.'.$this->value;
    }

    /** Name of the feature flag that makes this provider's routes and button available. */
    public function flagName(): string
    {
        return 'auth.'.$this->value.'.enabled';
    }
}
