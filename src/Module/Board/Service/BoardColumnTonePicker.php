<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Workflow\Contract\LabelTone;
use Random\Randomizer;

/** Chooses a new column's colour: a random one no column uses, the Backlog included, or any when all are taken. */
final readonly class BoardColumnTonePicker
{
    public function __construct(
        private Randomizer $randomizer = new Randomizer(),
    ) {
    }

    /** @param list<BoardColumn> $columns every column of the board, the Backlog included, because its tag shows on a card */
    public function pick(array $columns): LabelTone
    {
        $used = array_map(static fn (BoardColumn $column): LabelTone => $column->tone, $columns);
        $free = array_values(array_filter(LabelTone::cases(), static fn (LabelTone $tone): bool => !\in_array($tone, $used, true)));
        $choices = [] === $free ? LabelTone::cases() : $free;

        return $choices[$this->randomizer->getInt(0, \count($choices) - 1)];
    }
}
