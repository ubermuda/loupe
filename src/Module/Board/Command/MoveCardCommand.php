<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\Actor;

final readonly class MoveCardCommand
{
    /**
     * @param ?int    $position     the rank the card takes inside the target column,
     *                              counting from 0; null appends it to the end
     * @param ?string $parent       null or blank keeps the parent, `none` clears it,
     *                              and a card id sets it
     * @param ?string $beforeCardId a card of the target column the card lands above;
     *                              it wins over $position
     * @param ?string $afterCardId  a card of the target column the card lands below,
     *                              read only when $beforeCardId is absent
     * @param ?User   $unmanageBy   the person who holds the card with the move, once
     *                              the caller checked that they may manage the project
     */
    public function __construct(
        public Card $card,
        public Actor $actor,
        public BoardColumn $column,
        public ?int $position = null,
        public ?string $parent = null,
        public ?string $beforeCardId = null,
        public ?string $afterCardId = null,
        public ?User $unmanageBy = null,
    ) {
    }
}
