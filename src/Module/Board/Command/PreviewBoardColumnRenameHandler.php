<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

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
        $slug = $this->rules->slugFor($command->label);

        return new PreviewBoardColumnRenameView(
            $column,
            $slug,
            $this->rules->refuseRename($this->boardColumns->findForProject($column->project), $column, $slug),
        );
    }
}
