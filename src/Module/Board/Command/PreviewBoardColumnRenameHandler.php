<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Service\BoardColumns;

/**
 * The slug a rename would write, derived by the same slugger the rename uses,
 * so the dialog never promises a slug the server then does not store.
 */
final readonly class PreviewBoardColumnRenameHandler
{
    public function __construct(
        private BoardColumnRepository $boardColumns,
        private BoardColumns $rules,
    ) {
    }

    public function __invoke(PreviewBoardColumnRenameCommand $command): PreviewBoardColumnRenameView
    {
        $column = $command->column;
        $label = trim($command->label);
        $slug = $this->rules->slugFor($label);

        return new PreviewBoardColumnRenameView(
            $column,
            $slug,
            (mb_strlen($label) > BoardColumn::MAX_LABEL_LENGTH ? 'board.column.error.label_too_long' : null)
                ?? $this->rules->refuseLabel($label)
                ?? $this->rules->refuseRename($this->boardColumns->findForProject($column->project), $column, $slug),
        );
    }
}
