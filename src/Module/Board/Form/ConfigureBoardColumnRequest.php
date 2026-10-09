<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Workflow\Contract\LabelTone;

final class ConfigureBoardColumnRequest extends RenameBoardColumnRequest
{
    public function __construct(
        ?string $label = null,
        ?string $expectedLabel = null,
        public bool $terminal = false,
        public ?string $expectedTerminal = null,
        ?LabelTone $tone = null,
    ) {
        parent::__construct($label, $expectedLabel);
        $this->tone = $tone;
    }
}
