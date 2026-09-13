<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\SetDefaultBoardColumnCommand;
use App\Module\Board\Command\SetDefaultBoardColumnHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Security\BoardColumnVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('board-column-default')]
#[IsGranted(BoardColumnVoter::MANAGE, subject: 'column')]
#[Route(
    '/projects/{projectId}/board/columns/{columnId}/default',
    name: 'app_board_column_default',
    requirements: ['columnId' => Requirement::UUID],
    methods: ['POST'],
)]
final class SetDefaultBoardColumnController extends AppController
{
    public function __construct(
        private readonly SetDefaultBoardColumnHandler $setDefault,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(columnId, projectId)')] BoardColumn $column,
    ): Response {
        $this->board->requireEnabled();

        try {
            ($this->setDefault)(new SetDefaultBoardColumnCommand($column));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans(array_first($e->errors)));
        }

        return $this->redirectToRoute('app_project_board', ['id' => (string) $column->project->id]);
    }
}
