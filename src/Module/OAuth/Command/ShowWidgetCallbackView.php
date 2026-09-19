<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

final readonly class ShowWidgetCallbackView
{
    /**
     * @param ?string              $targetOrigin the one origin the page may post to, or null to post nothing
     * @param array<string, string> $message      what the widget receives
     */
    public function __construct(
        public ?string $targetOrigin,
        public array $message,
    ) {
    }
}
