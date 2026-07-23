<?php

namespace App\Module\Account\Command;

final readonly class ResetPasswordCommand
{
    public function __construct(
        public string $token,
        public string $plainPassword,
    ) {
    }
}
