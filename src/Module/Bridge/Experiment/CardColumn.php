<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** The column a card stands in. A seeded column's label is a translation key. */
final readonly class CardColumn
{
    public function __construct(
        public string $label,
        public bool $terminal,
    ) {
    }
}
