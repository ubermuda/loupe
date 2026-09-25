<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\SetBoardColumnTerminalCommand;
use App\Module\Board\Command\SetBoardColumnTerminalHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('board-column-terminal')]
#[IsGranted(BoardColumnVoter::MANAGE, subject: 'column')]
#[Route(
    '/projects/{projectId}/board/columns/{columnId}/terminal',
    name: 'app_board_column_terminal',
    requirements: ['columnId' => Requirement::UUID],
    defaults: ['terminal' => true],
    methods: ['POST'],
)]
#[Route(
    '/projects/{projectId}/board/columns/{columnId}/not-terminal',
    name: 'app_board_column_not_terminal',
    requirements: ['columnId' => Requirement::UUID],
    defaults: ['terminal' => false],
    methods: ['POST'],
)]
final class SetBoardColumnTerminalController extends AppController
{
    public function __construct(
        private readonly SetBoardColumnTerminalHandler $setTerminal,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        bool $terminal,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        $this->board->requireEnabled();

        try {
            ($this->setTerminal)(new SetBoardColumnTerminalCommand($column, $terminal, CardReporter::Human));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans(array_first($e->errors)));
        }

        return $this->redirectToRoute('app_project_board', ['id' => (string) $column->project->id]);
    }
}
