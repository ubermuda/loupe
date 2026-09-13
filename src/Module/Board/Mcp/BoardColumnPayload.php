<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one shape every board tool returns a board's columns in.
 *
 * @phpstan-type BoardColumnSummary array{slug: string, label: string, terminal: bool, default: bool}
 */
final readonly class BoardColumnPayload
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<BoardColumn> $columns
     *
     * @return list<BoardColumnSummary>
     */
    public function forColumns(array $columns): array
    {
        return array_map(
            fn (BoardColumn $column): array => [
                'slug' => $column->slug,
                // A seeded label is a translation key, and a renamed one is text that no key matches.
                'label' => $this->translator->trans($column->label),
                'terminal' => $column->terminal,
                'default' => $column->isDefault,
            ],
            $columns,
        );
    }
}
