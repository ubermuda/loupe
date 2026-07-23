<?php

namespace App\Module\Account\Command;

final readonly class ResendVerificationEmailCommand
{
    public function __construct(
        public string $email,
    ) {
    }
}
