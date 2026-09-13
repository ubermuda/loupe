<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\RenameBoardColumnCommand;
use App\Module\Board\Command\RenameBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Form\RenameBoardColumnFormType;
use App\Module\Board\Form\RenameBoardColumnRequest;
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

#[IsGranted(BoardColumnVoter::MANAGE, subject: 'column')]
#[Route(
    '/projects/{projectId}/board/columns/{columnId}/rename',
    name: 'app_board_column_rename',
    requirements: ['columnId' => Requirement::UUID],
    methods: ['POST'],
)]
final class RenameBoardColumnController extends AppController
{
    public function __construct(
        private readonly RenameBoardColumnHandler $renameColumn,
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

        $project = $column->project;
        $data = new RenameBoardColumnRequest();
        $form = $this->formFactory->createNamed(RenameBoardColumnFormType::nameFor($column), RenameBoardColumnFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->renameColumn)(new RenameBoardColumnCommand($column, $data->label ?? ''));

                return $this->redirectToRoute('app_project_board', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                // The forwarded board has no dialog for a column that went away.
                if (isset($e->errors['column'])) {
                    $this->addFlash('error', $this->translator->trans($e->errors['column']));

                    return $this->redirectToRoute('app_project_board', ['id' => (string) $project->id]);
                }
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ShowBoardController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            'renameColumnForm' => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
