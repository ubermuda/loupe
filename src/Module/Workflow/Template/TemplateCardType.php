<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Board\Entity\LabelTone;

/** A card type the template declares. A type with children can be a parent, and a type with a lane gets a lane on the board. */
final readonly class TemplateCardType
{
    /** @param string $label a translation key */
    public function __construct(
        public string $key,
        public string $label,
        public LabelTone $tone,
        public bool $children,
        public bool $lane,
    ) {
    }
}
