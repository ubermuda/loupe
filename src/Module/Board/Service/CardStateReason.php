<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

/** One reason a card has a state: a code, the parameters of its sentence, and the time it began. */
final readonly class CardStateReason
{
    /** @param array<string, string|int> $params the placeholders of the translation key of the code */
    public function __construct(
        public CardStateCode $code,
        public array $params = [],
        public ?\DateTimeImmutable $since = null,
    ) {
    }

    public function translationKey(): string
    {
        return $this->code->translationKey();
    }
}
