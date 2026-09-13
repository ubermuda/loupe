<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;

/** The part of a column the board rules read, detached so a proposed change never touches an entity. */
final readonly class BoardColumnShape
{
    public function __construct(
        public string $slug,
        public bool $terminal,
        public bool $isDefault,
    ) {
    }

    public static function of(BoardColumn $column): self
    {
        return new self($column->slug, $column->terminal, $column->isDefault);
    }
}
