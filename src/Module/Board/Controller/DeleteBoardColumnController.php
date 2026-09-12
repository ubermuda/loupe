<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Form\DeleteBoardColumnFormType;
use App\Module\Board\Form\DeleteBoardColumnRequest;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An empty column posts this form straight from its menu. A column with cards
 * posts it from a dialog that picks the target, and the handler decides which
 * case applies from the count it reads under the project lock.
 */
#[IsGranted(BoardColumnVoter::MANAGE, subject: 'column')]
#[Route(
    '/projects/{projectId}/board/columns/{columnId}/delete',
    name: 'app_board_column_delete',
    requirements: ['columnId' => Requirement::UUID],
    methods: ['POST'],
)]
final class DeleteBoardColumnController extends AppController
{
    public function __construct(
        private readonly DeleteBoardColumnHandler $deleteColumn,
        private readonly FormFactoryInterface $formFactory,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        $this->board->requireEnabled();

        $projectId = (string) $column->project->id;
        $label = $this->translator->trans($column->label);
        $data = new DeleteBoardColumnRequest();
        $form = $this->formFactory->createNamed(DeleteBoardColumnFormType::nameFor($column), DeleteBoardColumnFormType::class, $data, ['column' => $column]);
        $form->handleRequest($request);

        // Every refusal here is a page that went stale, because the page offers
        // only the valid choices. So it redirects with a flash rather than
        // re-rendering a dialog the reader has nothing to correct in.
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans(DeleteBoardColumnHandler::TARGET_INVALID));

            return $this->redirectToRoute('app_project_board', ['id' => $projectId]);
        }

        try {
            $deleted = ($this->deleteColumn)(new DeleteBoardColumnCommand($column, $data->target));
            $this->addFlash('success', $this->translator->trans('board.column.flash.deleted', [
                '%label%' => $label,
                '%count%' => \count($deleted->movedCardIds),
            ]));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans(array_first($e->errors)));
        }

        return $this->redirectToRoute('app_project_board', ['id' => $projectId]);
    }
}
