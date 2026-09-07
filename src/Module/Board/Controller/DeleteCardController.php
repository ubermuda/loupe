<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('board-card-delete')]
#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/delete',
    name: 'app_board_card_delete',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class DeleteCardController extends AppController
{
    public function __construct(
        private readonly DeleteCardHandler $deleteCard,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $this->board->requireEnabled();

        $projectId = (string) $card->project->id;
        $title = $card->title;

        ($this->deleteCard)(new DeleteCardCommand($card));

        $this->addFlash('success', $this->translator->trans('board.card.flash.deleted', ['%title%' => $title]));

        return $this->redirectToRoute('app_project_board', ['id' => $projectId]);
    }
}
