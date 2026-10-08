<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\LabelTone;

final readonly class CardTypeDefinition
{
    /** @param string $label a translation key, or the key itself for a type the template does not declare */
    public function __construct(
        public string $key,
        public string $label,
        public LabelTone $tone,
        public bool $children,
        public bool $lane,
    ) {
    }
}
