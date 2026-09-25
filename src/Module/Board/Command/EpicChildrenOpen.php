<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/**
 * Thrown when a person or an agent moves an epic to a terminal column while a
 * child is open. It carries the numbers of the open children, because the only
 * useful answer names them.
 */
final class EpicChildrenOpen extends \DomainException
{
    /** Takes the parameter %cards%, filled from cardList(). */
    public const string MESSAGE = 'board.card.error.epic_children_open';

    /** @param non-empty-list<int> $numbers */
    public function __construct(
        public readonly array $numbers,
    ) {
        parent::__construct(\sprintf('The epic has open children: %s.', $this->cardList()));
    }

    /** The numbers as a reader writes them, such as "#231, #232". */
    public function cardList(): string
    {
        return implode(', ', array_map(static fn (int $number): string => '#'.$number, $this->numbers));
    }
}
