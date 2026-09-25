<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Form\MoveCardFormType;
use App\Module\Board\Form\MoveCardRequest;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The endpoint a drop, and the card's own move controls, both submit to.
 *
 * A drop answers with the moved card placed where the database holds it, so
 * the page corrects a wrong prediction without a reload of the board. A
 * refused drop answers 422 with no body, and the drag puts the card back.
 */
#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/move',
    name: 'app_board_card_move',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class MoveCardController extends AppController
{
    public function __construct(
        private readonly MoveCardHandler $moveCard,
        private readonly ShowCardPlacementHandler $showPlacement,
        private readonly FormFactoryInterface $formFactory,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $this->board->requireEnabled();

        $project = $card->project;
        $data = new MoveCardRequest();
        $stream = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
        $error = null;

        // Rebuilt under the name the board rendered it with, so handleRequest()
        // finds the submission and the form component checks its own CSRF token.
        $form = $this->formFactory->createNamed(MoveCardFormType::nameFor($card), MoveCardFormType::class, $data, ['project' => $project]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // A stale or forged submission, which the reader cannot correct.
            $error = 'board.card.flash.move_rejected';
        } else {
            try {
                ($this->moveCard)(new MoveCardCommand(
                    card: $card,
                    actor: CardReporter::Human,
                    column: $data->column ?? throw new \LogicException('column required after validation'),
                    position: $data->position,
                ));
            } catch (DomainErrors $e) {
                // The column went away between the form check and the lock.
                $error = array_first($e->errors);
            }
        }

        if (null !== $error && $stream) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$stream) {
            if (null !== $error) {
                $this->addFlash('error', $this->translator->trans($error));
            }

            return $this->redirectToRoute('app_project_board', ['id' => (string) $project->id]);
        }

        return new Response(
            $this->renderView('@Board/_card_placement.stream.html.twig', [
                'cardId' => (string) $card->id,
                'placement' => ($this->showPlacement)(new ShowCardPlacementCommand($project, $card)),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
