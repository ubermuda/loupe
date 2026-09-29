<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Utils\Slug;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rules every board keeps: at least one terminal column, exactly one
 * Backlog row with the slug backlog that is not terminal, and unique
 * well-formed slugs. No person renames, configures or deletes the Backlog.
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
    public const string NO_SINGLE_BACKLOG = 'board.column.error.no_single_backlog';
    public const string BACKLOG_TERMINAL = 'board.column.error.backlog_terminal';
    public const string BACKLOG_SLUG = 'board.column.error.backlog_slug';
    public const string BACKLOG_LOCKED = 'board.column.error.backlog_locked';
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
        $shapes[] = new BoardColumnShape($slug, terminal: false, backlog: false);

        return $this->violation($shapes);
    }

    /** @param list<BoardColumn> $columns */
    public function refuseRename(array $columns, BoardColumn $renamed, string $slug): ?string
    {
        if ($renamed->backlog) {
            return self::BACKLOG_LOCKED;
        }

        return $this->violation(array_map(
            static fn (BoardColumn $column): BoardColumnShape => $column === $renamed
                ? new BoardColumnShape($slug, $column->terminal, $column->backlog)
                : BoardColumnShape::of($column),
            $columns,
        ));
    }

    /**
     * Both settings at once, so the answer does not depend on the order the
     * changes would apply in.
     *
     * @param list<BoardColumn> $columns
     */
    public function refuseConfigure(array $columns, BoardColumn $configured, string $slug, bool $terminal): ?string
    {
        if ($configured->backlog) {
            return self::BACKLOG_LOCKED;
        }

        return $this->violation(array_map(
            static fn (BoardColumn $column): BoardColumnShape => $column === $configured
                ? new BoardColumnShape($slug, $terminal, $column->backlog)
                : BoardColumnShape::of($column),
            $columns,
        ));
    }

    /** @param list<BoardColumn> $columns */
    public function refuseDelete(array $columns, BoardColumn $deleted): ?string
    {
        if ($deleted->backlog) {
            return self::BACKLOG_LOCKED;
        }

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

        $backlogs = array_values(array_filter($shapes, static fn (BoardColumnShape $shape): bool => $shape->backlog));
        if (1 !== \count($backlogs)) {
            return self::NO_SINGLE_BACKLOG;
        }
        if (BoardColumn::BACKLOG_SLUG !== $backlogs[0]->slug) {
            return self::BACKLOG_SLUG;
        }

        return $backlogs[0]->terminal ? self::BACKLOG_TERMINAL : null;
    }
}
