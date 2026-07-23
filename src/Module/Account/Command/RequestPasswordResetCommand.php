<?php

namespace App\Module\Account\Command;

final readonly class RequestPasswordResetCommand
{
    public function __construct(
        public string $email,
    ) {
    }
}
