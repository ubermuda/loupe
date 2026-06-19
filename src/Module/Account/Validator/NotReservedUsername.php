<?php

declare(strict_types=1);

namespace App\Module\Account\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class NotReservedUsername extends Constraint
{
    public string $message = 'account.registration.validator.username_reserved';
}
