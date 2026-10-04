<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\BulkMoveBacklogCardsCommand;
use App\Module\Board\Command\BulkMoveBacklogCardsHandler;
use App\Module\Board\Command\CardManaged;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\ListBacklogCardsCommand;
use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Command\ListBacklogPageIdsCommand;
use App\Module\Board\Command\ListBacklogPageIdsHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Form\BulkMoveBacklogCardsFormType;
use App\Module\Board\Form\BulkMoveBacklogCardsRequest;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\View\BacklogListQuery;
use App\Module\Board\View\BacklogPageChange;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
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
 * The bulk bar of the Backlog page. It names many cards, so the handler checks
 * CardVoter::WRITE on each card it loads, and refuses a card of another project
 * or outside the Backlog. The project gate here only keeps strangers out.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/backlog/bulk-move',
    name: 'app_project_board_backlog_bulk_move',
    requirements: ['projectId' => Requirement::UUID],
    methods: ['POST'],
)]
final class BulkMoveBacklogCardsController extends AppController
{
    public function __construct(
        private readonly BulkMoveBacklogCardsHandler $bulkMove,
        private readonly ListBacklogCardsHandler $listBacklogCards,
        private readonly ListBacklogPageIdsHandler $listBacklogPageIds,
        private readonly FormFactoryInterface $formFactory,
        private readonly BoardAvailability $board,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findBacklogForProjectId(projectId)')] BoardColumn $backlog,
    ): Response {
        $this->board->requireEnabled();

        $listQuery = BacklogListQuery::fromQuery($request->query);
        $data = new BulkMoveBacklogCardsRequest();
        $stream = TurboBundle::STREAM_FORMAT === $request->getPreferredFormat();
        $error = null;
        $moved = [];

        $form = $this->formFactory->createNamed(BulkMoveBacklogCardsFormType::NAME, BulkMoveBacklogCardsFormType::class, $data, ['backlog' => $backlog]);
        $form->handleRequest($request);
        // The rows the page shows, read before the move takes any of them.
        $shownIds = $stream
            ? ($this->listBacklogPageIds)(new ListBacklogPageIdsCommand($backlog, $listQuery))
            : [];

        if (!$form->isSubmitted() || !$form->isValid()) {
            $error = $this->translator->trans('board.card.flash.move_rejected');
        } else {
            try {
                $moved = ($this->bulkMove)(new BulkMoveBacklogCardsCommand(
                    $backlog,
                    $data->ids,
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

        // A refusal inside the transaction rolls the moves back and closes the
        // entity manager, so neither answer reads the database again.
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
                'projectId' => (string) $project->id,
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
                'project' => $project,
                'movedIds' => array_map(static fn (Card $card): string => (string) $card->id, $moved),
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
