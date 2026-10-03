<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\SaveBoardTerminalWindowCommand;
use App\Module\Board\Command\SaveBoardTerminalWindowHandler;
use App\Module\Board\Form\SaveBoardTerminalWindowFormType;
use App\Module\Board\Form\SaveBoardTerminalWindowRequest;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(BoardColumnVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/board/terminal-window',
    name: 'app_board_terminal_window_save',
    methods: ['POST'],
)]
final class SaveBoardTerminalWindowController extends AppController
{
    public function __construct(
        private readonly SaveBoardTerminalWindowHandler $saveWindow,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->board->requireEnabled();

        $data = new SaveBoardTerminalWindowRequest();
        $form = $this->createForm(SaveBoardTerminalWindowFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $days = $data->terminalWindowDays ?? throw new \LogicException('terminal window required after validation');
            ($this->saveWindow)(new SaveBoardTerminalWindowCommand($project, $days));
            $this->addFlash('success', $this->translator->trans('board.terminal_window.flash.saved', ['%count%' => $days]));

            return $this->redirectToRoute('app_board_settings', ['id' => (string) $project->id]);
        }

        // Board settings renders the refused form with its errors. The id travels
        // beside the entity, because the layout resolves the project from it.
        return $this->forward(ShowBoardController::class, [
            'id' => (string) $project->id,
            'project' => $project,
            'terminalWindowForm' => $form->createView(),
            'boardSettings' => true,
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
