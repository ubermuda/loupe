<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
final readonly class BuildPreviewLoginLinkCommand
{
    public function __construct(
        public string $email,
        public string $path = '/',
        public int $lifetimeSeconds = 900,
    ) {
    }
}
