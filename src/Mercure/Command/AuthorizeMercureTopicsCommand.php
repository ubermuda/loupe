<?php

declare(strict_types=1);

namespace App\Mercure\Command;

final readonly class AuthorizeMercureTopicsCommand
{
    /** @param list<string> $topics every topic the open page listens on */
    public function __construct(
        public array $topics,
    ) {
    }
}
