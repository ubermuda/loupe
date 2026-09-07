<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\MoveCardCommand;
use App\Module\Board\Command\MoveCardHandler;
use App\Module\Board\Command\ShowBoardCommand;
use App\Module\Board\Command\ShowBoardHandler;
use App\Module\Board\Entity\Card;
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
 * The server is the authority on the result: the response carries the whole
 * board back, so a drag that was interrupted or refused cannot leave the page
 * showing an order the database does not have.
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
        private readonly ShowBoardHandler $showBoard,
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

        // Rebuilt under the name the board rendered it with, so handleRequest()
        // finds the submission and the form component checks its own CSRF token.
        $form = $this->formFactory->createNamed(MoveCardFormType::nameFor($card), MoveCardFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // A rejection is a stale or forged submission rather than something
            // the reader could correct, so it redirects: the flash below is
            // rendered outside the board, which a stream response never carries.
            $this->addFlash('error', $this->translator->trans('board.card.flash.move_rejected'));

            return $this->redirectToRoute('app_project_board', ['id' => (string) $project->id]);
        }

        ($this->moveCard)(new MoveCardCommand(
            card: $card,
            status: $data->status ?? throw new \LogicException('status required after validation'),
            priority: $data->priority ?? throw new \LogicException('priority required after validation'),
            position: $data->position,
        ));

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_project_board', ['id' => (string) $project->id]);
        }

        return new Response(
            $this->renderView('@Board/_board.stream.html.twig', [
                'board' => ($this->showBoard)(new ShowBoardCommand($project)),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
