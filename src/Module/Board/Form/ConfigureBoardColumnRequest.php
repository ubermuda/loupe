<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\LabelTone;

final class ConfigureBoardColumnRequest extends RenameBoardColumnRequest
{
    public function __construct(
        ?string $label = null,
        ?string $expectedLabel = null,
        public bool $isDefault = false,
        public bool $terminal = false,
        public ?string $expectedDefaultId = null,
        public ?string $expectedTerminal = null,
        ?LabelTone $tone = null,
    ) {
        parent::__construct($label, $expectedLabel);
        $this->tone = $tone;
    }
}
