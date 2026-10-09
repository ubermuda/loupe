<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** One wait of a wait item as the page shows it: a translation key with its values, and the link that closes the wait. */
final readonly class CardWaitLine
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public string $key,
        public array $params,
        public ?string $href,
        public ?string $detail,
    ) {
    }

    public function actionKey(): string
    {
        return $this->key.'.action';
    }
}
