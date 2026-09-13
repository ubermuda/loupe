<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ReorderBoardColumnsCommand;
use App\Module\Board\Command\ReorderBoardColumnsHandler;
use App\Module\Board\Form\ReorderBoardColumnsFormType;
use App\Module\Board\Form\ReorderBoardColumnsRequest;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** The endpoint a header drop, and each move left or right, submit to. */
#[IsGranted(BoardColumnVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/board/columns/reorder',
    name: 'app_board_columns_reorder',
    methods: ['POST'],
)]
final class ReorderBoardColumnsController extends AppController
{
    public function __construct(
        private readonly ReorderBoardColumnsHandler $reorderColumns,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->board->requireEnabled();

        $data = new ReorderBoardColumnsRequest();
        $form = $this->createForm(ReorderBoardColumnsFormType::class, $data);
        $form->handleRequest($request);

        // The order is written by the page rather than typed, so a refusal is a
        // stale page, and the reader gets the board as it now is.
        $refusal = ReorderBoardColumnsHandler::ORDER_STALE;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->reorderColumns)(new ReorderBoardColumnsCommand($project, $data->order ?? ''));
                $refusal = null;
            } catch (DomainErrors $e) {
                $refusal = array_first($e->errors);
            }
        }

        if (null !== $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal));
        }

        return $this->redirectToRoute('app_project_board', ['id' => (string) $project->id]);
    }
}
