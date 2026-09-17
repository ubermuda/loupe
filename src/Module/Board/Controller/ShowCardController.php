<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(CardVoter::VIEW, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}',
    name: 'app_board_card',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowCardController extends AppController
{
    public const string REFUSED_FEEDBACK_REPLY = 'refusedFeedbackReply';

    public function __construct(
        private readonly BoardAvailability $board,
        private readonly ShowCardHandler $handler,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $this->board->requireEnabled();

        $view = ($this->handler)(new ShowCardCommand($card));

        $response = $this->render('@Board/show_card.html.twig', [
            'card' => $view->card,
            'siteReviewLinks' => $view->siteReviewLinks,
            'siteReviewReplies' => $view->siteReviewReplies,
            'refusedFeedbackReply' => $this->getInjectedFormView($request, self::REFUSED_FEEDBACK_REPLY),
        ]);
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
