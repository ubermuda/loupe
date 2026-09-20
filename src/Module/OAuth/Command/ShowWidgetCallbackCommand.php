<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

final readonly class ShowWidgetCallbackCommand
{
    public function __construct(
        public string $state,
        public string $code,
        public string $error,
    ) {
    }
}
