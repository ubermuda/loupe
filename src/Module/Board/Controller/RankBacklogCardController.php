<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\RankBacklogCardCommand;
use App\Module\Board\Command\RankBacklogCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Form\RankBacklogCardFormType;
use App\Module\Board\Form\RankBacklogCardRequest;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\View\BacklogListQuery;
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
 * The endpoint a drag on the Backlog page submits to. The drag already moved
 * the row, so a stream answer is empty. A refused rank answers 422 with no
 * body, and the drag puts the row back.
 */
#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/backlog/cards/{cardId}/rank',
    name: 'app_project_board_backlog_rank',
    requirements: ['projectId' => Requirement::UUID, 'cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class RankBacklogCardController extends AppController
{
    public function __construct(
        private readonly RankBacklogCardHandler $rankCard,
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

        $data = new RankBacklogCardRequest();
        $stream = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
        $error = null;

        $form = $this->formFactory->createNamed(RankBacklogCardFormType::nameFor($card), RankBacklogCardFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $error = $this->translator->trans('board.card.flash.move_rejected');
        } else {
            try {
                ($this->rankCard)(new RankBacklogCardCommand($card, CardReporter::Human, $data->beforeCardId, $data->afterCardId));
            } catch (DomainErrors $e) {
                $error = $this->translator->trans(array_first($e->errors));
            }
        }

        if (null !== $error && $stream) {
            return new Response('', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$stream) {
            if (null !== $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_project_board_backlog', [
                'projectId' => (string) $card->project->id,
                ...BacklogListQuery::fromQuery($request->query)->routeParams(),
            ]);
        }

        return new Response('', Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
