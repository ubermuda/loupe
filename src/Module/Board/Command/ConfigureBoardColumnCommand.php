<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\LabelTone;

/** The expected values are what the dialog showed, so a newer edit is not overwritten. */
final readonly class ConfigureBoardColumnCommand
{
    public function __construct(
        public BoardColumn $column,
        public CardReporter $actor,
        public string $label,
        public bool $isDefault,
        public bool $terminal,
        public string $expectedLabel,
        public string $expectedDefaultId,
        public bool $expectedTerminal,
        /** Null keeps the column's colour. */
        public ?LabelTone $tone = null,
    ) {
    }
}
