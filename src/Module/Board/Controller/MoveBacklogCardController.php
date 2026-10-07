<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\ListBacklogCardsCommand;
use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Command\ListBacklogPageIdsCommand;
use App\Module\Board\Command\ListBacklogPageIdsHandler;
use App\Module\Board\Command\MoveBacklogCardCommand;
use App\Module\Board\Command\MoveBacklogCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Form\MoveBacklogCardFormType;
use App\Module\Board\Form\MoveBacklogCardRequest;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Board\View\BacklogPageChange;
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
 * The Move to menu of one Backlog row. A stream answer removes the row and
 * shows a confirmation. The query string carries the page and the filters.
 */
#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/backlog/cards/{cardId}/move',
    name: 'app_project_board_backlog_move',
    requirements: ['projectId' => Requirement::UUID, 'cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class MoveBacklogCardController extends AppController
{
    public function __construct(
        private readonly MoveBacklogCardHandler $moveCard,
        private readonly ListBacklogCardsHandler $listBacklogCards,
        private readonly ListBacklogPageIdsHandler $listBacklogPageIds,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        // Read before the move, which gives the card its new column.
        $backlog = $card->column;
        $listQuery = BacklogListQuery::fromQuery($request->query);
        $data = new MoveBacklogCardRequest();
        $stream = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
        $error = null;

        $form = $this->formFactory->createNamed(MoveBacklogCardFormType::nameFor($card), MoveBacklogCardFormType::class, $data, ['backlog' => $backlog]);
        $form->handleRequest($request);
        // The rows the page shows, read before the move takes any of them.
        $shownIds = $stream
            ? ($this->listBacklogPageIds)(new ListBacklogPageIdsCommand($backlog, $listQuery))
            : [];

        if (!$form->isSubmitted() || !$form->isValid()) {
            $error = $this->translator->trans('board.card.flash.move_rejected');
        } else {
            try {
                ($this->moveCard)(new MoveBacklogCardCommand(
                    $card,
                    CardReporter::Human,
                    $data->column ?? throw new \LogicException('column required after validation'),
                ));
            } catch (DomainErrors $e) {
                $error = $this->translator->trans(array_first($e->errors));
            } catch (EpicChildrenOpen $e) {
                $error = $this->translator->trans(EpicChildrenOpen::MESSAGE, ['%cards%' => $e->cardList()]);
            } catch (CardManaged) {
                $error = $this->translator->trans(CardManaged::MESSAGE);
            }
        }

        if (null !== $error && $stream) {
            return new Response(
                $this->renderView('@Board/_backlog_refused.stream.html.twig', ['error' => $error]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        if (!$stream) {
            if (null !== $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('app_project_board_backlog', [
                'projectId' => (string) $card->project->id,
                ...$listQuery->routeParams(),
            ]);
        }

        $view = ($this->listBacklogCards)(new ListBacklogCardsCommand($backlog, $listQuery));
        $change = BacklogPageChange::between($shownIds, $view->items);
        if (null !== $view->clampedPage) {
            $listQuery = $listQuery->withPage($view->clampedPage);
            $view = ($this->listBacklogCards)(new ListBacklogCardsCommand($backlog, $listQuery));
        }

        return new Response(
            $this->renderView('@Board/_backlog_moved.stream.html.twig', [
                'project' => $card->project,
                'movedIds' => [(string) $card->id],
                'column' => $data->column,
                'view' => $view,
                'listQuery' => $listQuery,
                'refill' => $change->redraws,
                'goneIds' => $change->goneIds,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
