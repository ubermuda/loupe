<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\AddBoardColumnCommand;
use App\Module\Board\Command\AddBoardColumnHandler;
use App\Module\Board\Form\AddBoardColumnFormType;
use App\Module\Board\Form\AddBoardColumnRequest;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(BoardColumnVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/board/columns',
    name: 'app_board_column_add',
    methods: ['POST'],
)]
final class AddBoardColumnController extends AppController
{
    public function __construct(
        private readonly AddBoardColumnHandler $addColumn,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->board->requireEnabled();

        $data = new AddBoardColumnRequest();
        $form = $this->createForm(AddBoardColumnFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->addColumn)(new AddBoardColumnCommand($project, $data->label ?? '', $data->tone));

                return $this->redirectToRoute('app_board_settings', ['id' => (string) $project->id]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        // Board settings renders the refused form with its errors. The id travels
        // beside the entity, because the layout resolves the project from it.
        return $this->forward(ShowBoardController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            'addColumnForm' => $form->createView(),
            'boardSettings' => true,
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
