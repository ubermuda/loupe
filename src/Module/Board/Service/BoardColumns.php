<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Utils\Slug;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rules every board keeps: at least one terminal column, exactly one
 * default column, a default that is not terminal, and unique well-formed slugs.
 *
 * Each method takes the board as it is now, applies one change to a copy, and
 * answers with the translation key of the first rule the result breaks, or null.
 * Nothing here writes, so a refused change leaves no dirty entity behind.
 */
final readonly class BoardColumns
{
    public const string SLUG_EMPTY = 'board.column.error.slug_empty';
    public const string SLUG_INVALID = 'board.column.error.slug_invalid';
    public const string SLUG_TAKEN = 'board.column.error.slug_taken';
    public const string NO_TERMINAL = 'board.column.error.no_terminal';
    public const string NO_SINGLE_DEFAULT = 'board.column.error.no_single_default';
    public const string DEFAULT_TERMINAL = 'board.column.error.default_terminal';
    public const string LABEL_RESERVED = 'board.column.error.label_reserved';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function slugFor(string $label): string
    {
        return Slug::fromName(trim($label));
    }

    /**
     * Every place that shows a label translates it, because a seeded label is a
     * translation key. A typed label that is itself a key would show that key's
     * message instead, so it is refused.
     */
    public function refuseLabel(string $label): ?string
    {
        $label = trim($label);

        return $this->translator->trans($label) === $label ? null : self::LABEL_RESERVED;
    }

    /** @param list<BoardColumn> $columns */
    public function refuseAdd(array $columns, string $slug): ?string
    {
        $shapes = array_map(BoardColumnShape::of(...), $columns);
        $shapes[] = new BoardColumnShape($slug, terminal: false, isDefault: false);

        return $this->violation($shapes);
    }

    /** @param list<BoardColumn> $columns */
    public function refuseRename(array $columns, BoardColumn $renamed, string $slug): ?string
    {
        return $this->violation(array_map(
            static fn (BoardColumn $column): BoardColumnShape => $column === $renamed
                ? new BoardColumnShape($slug, $column->terminal, $column->isDefault)
                : BoardColumnShape::of($column),
            $columns,
        ));
    }

    /** @param list<BoardColumn> $columns */
    public function refuseTerminal(array $columns, BoardColumn $flagged, bool $terminal): ?string
    {
        return $this->violation(array_map(
            static fn (BoardColumn $column): BoardColumnShape => $column === $flagged
                ? new BoardColumnShape($column->slug, $terminal, $column->isDefault)
                : BoardColumnShape::of($column),
            $columns,
        ));
    }

    /** @param list<BoardColumn> $columns */
    public function refuseDefault(array $columns, BoardColumn $chosen): ?string
    {
        return $this->violation(array_map(
            static fn (BoardColumn $column): BoardColumnShape => new BoardColumnShape($column->slug, $column->terminal, $column === $chosen),
            $columns,
        ));
    }

    /** @param list<BoardColumn> $columns */
    public function refuseDelete(array $columns, BoardColumn $deleted): ?string
    {
        return $this->violation(array_values(array_map(
            BoardColumnShape::of(...),
            array_filter($columns, static fn (BoardColumn $column): bool => $column !== $deleted),
        )));
    }

    /** @param list<BoardColumnShape> $shapes */
    private function violation(array $shapes): ?string
    {
        $slugs = [];
        foreach ($shapes as $shape) {
            if ('' === $shape->slug) {
                return self::SLUG_EMPTY;
            }
            if (1 !== preg_match('/'.Slug::PATTERN.'/', $shape->slug)) {
                return self::SLUG_INVALID;
            }
            if (isset($slugs[$shape->slug])) {
                return self::SLUG_TAKEN;
            }
            $slugs[$shape->slug] = true;
        }

        $terminals = array_filter($shapes, static fn (BoardColumnShape $shape): bool => $shape->terminal);
        if ([] === $terminals) {
            return self::NO_TERMINAL;
        }

        $defaults = array_values(array_filter($shapes, static fn (BoardColumnShape $shape): bool => $shape->isDefault));
        if (1 !== \count($defaults)) {
            return self::NO_SINGLE_DEFAULT;
        }

        return $defaults[0]->terminal ? self::DEFAULT_TERMINAL : null;
    }
}
