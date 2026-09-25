<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\SetCardLaneFormType;
use App\Module\Board\Form\SetCardLaneRequest;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\BoardAvailability;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/lane',
    name: 'app_board_card_lane',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class SetCardLaneController extends AppController
{
    public function __construct(
        private readonly UpdateCardHandler $updateCard,
        private readonly ShowBoardHandler $showBoard,
        private readonly BoardAvailability $board,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $this->board->requireEnabled();

        $data = new SetCardLaneRequest();
        $form = $this->formFactory->createNamed(SetCardLaneFormType::nameFor($card), SetCardLaneFormType::class, $data);
        $form->handleRequest($request);

        $refusal = 'board.card.lane.error.not_saved';
        if (CardType::Epic !== $card->type) {
            $refusal = 'board.card.lane.error.not_epic';
            $this->logger->info('board.card_lane_refused', ['cardId' => (string) $card->id, 'type' => $card->type->value]);
        } elseif ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->updateCard)(new UpdateCardCommand($card, CardReporter::Human, laneEnabled: '1' === $data->laneEnabled));
                $refusal = null;
            } catch (DomainErrors $e) {
                $refusal = array_first($e->errors);
            }
        }
        if (null !== $refusal) {
            $this->addFlash('error', $this->translator->trans($refusal));
        }

        $projectId = (string) $card->project->id;

        if (SetCardLaneRequest::RETURN_TO_BOARD !== $data->returnTo) {
            return $this->redirectToRoute('app_board_card', ['projectId' => $projectId, 'cardId' => (string) $card->id]);
        }

        // A refusal redirects, because its flash sits outside the board a stream replaces.
        if (null !== $refusal || TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_project_board', ['id' => $projectId]);
        }

        return new Response(
            $this->renderView('@Board/_board.stream.html.twig', [
                'board' => ($this->showBoard)(new ShowBoardCommand($card->project)),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
