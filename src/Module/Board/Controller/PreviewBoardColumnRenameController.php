<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\PreviewBoardColumnRenameCommand;
use App\Module\Board\Command\PreviewBoardColumnRenameHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The rename dialog's slug line, which the dialog reloads as the label is typed. */
#[IsGranted(BoardColumnVoter::MANAGE, subject: 'column')]
#[Route(
    '/projects/{projectId}/board/columns/{columnId}/rename-preview',
    name: 'app_board_column_rename_preview',
    requirements: ['columnId' => Requirement::UUID],
    methods: ['GET'],
)]
final class PreviewBoardColumnRenameController extends AppController
{
    public function __construct(
        private readonly PreviewBoardColumnRenameHandler $preview,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        $this->board->requireEnabled();

        $preview = ($this->preview)(new PreviewBoardColumnRenameCommand($column, $request->query->getString('label')));

        return $this->render('@Board/_board_column_slug.html.twig', [
            'column' => $preview->column,
            'slug' => $preview->slug,
            'refusal' => $preview->refusal,
        ]);
    }
}
