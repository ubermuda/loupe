<?php

namespace App\Module\Account\Command;

final readonly class VerifyEmailCommand
{
    public function __construct(
        public ?string $token,
    ) {
    }
}
