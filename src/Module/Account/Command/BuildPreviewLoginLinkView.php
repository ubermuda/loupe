<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Symfony\Component\DependencyInjection\Attribute\When;

#[When('dev')]
final readonly class BuildPreviewLoginLinkView
{
    public function __construct(
        public string $url,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
