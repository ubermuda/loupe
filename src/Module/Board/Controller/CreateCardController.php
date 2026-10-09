<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\FindBoardColumnCommand;
use App\Module\Board\Command\FindBoardColumnHandler;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Form\CreateCardFormType;
use App\Module\Board\Form\CreateCardRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardTypeCatalog;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/board/cards/new',
    name: 'app_board_card_create',
    methods: ['GET', 'POST'],
)]
final class CreateCardController extends AppController
{
    public function __construct(
        private readonly CreateCardHandler $createCard,
        private readonly FindBoardColumnHandler $findBoardColumn,
        private readonly ShowCardPlacementHandler $showPlacement,
        private readonly CardTypeCatalog $catalog,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        // `project` holds the raw id here, because the route aliases `id` to it.
        #[MapEntity(expr: 'repository.findBacklogForProjectId(project)')] BoardColumn $backlog,
    ): Response {
        $column = $this->column($request->query->getString('column'), $project, $backlog);

        $data = new CreateCardRequest(type: $this->catalog->forProject($project->requireId())->defaultKey, column: $column);
        $form = $this->createForm(CreateCardFormType::class, $data, ['project' => $project]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // NotBlank leaves "0" through, which `?:` would wrongly reject, so
            // the emptiness check is explicit.
            $title = trim($data->title ?? '');
            if ('' === $title) {
                throw new \LogicException('title required after validation');
            }

            try {
                $card = ($this->createCard)(new CreateCardCommand(
                    project: $project,
                    title: $title,
                    body: $data->body ?? '',
                    type: $data->type ?? $this->catalog->forProject($project->requireId())->defaultKey,
                    column: $data->column,
                    // A person filled this form in, whatever an agent may later do to the card.
                    reporter: Actor::Human,
                    pullRequestUrls: CreateCardRequest::toUrlList($data->pullRequestUrls),
                    relatedCards: $data->linkInputs(),
                    parentCardId: null === $data->parent ? null : (string) $data->parent->id,
                    source: new CardSource(CardSourceKind::Person),
                ));
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);

                return $this->renderFormResponse('@Board/create_card.html.twig', $form);
            }

            // The drawer closes on this answer, and the board places the card.
            if ('card-drawer-frame' === $request->headers->get('Turbo-Frame')
                && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                return new Response(
                    $this->renderView('@Board/_card_placement.stream.html.twig', [
                        'cardId' => (string) $card->id,
                        'placement' => ($this->showPlacement)(new ShowCardPlacementCommand($project, $card)),
                    ]),
                    Response::HTTP_OK,
                    ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
                );
            }

            return $this->redirectToRoute('app_board_card', [
                'projectId' => (string) $project->id,
                'cardId' => (string) $card->id,
            ]);
        }

        return $this->renderFormResponse('@Board/create_card.html.twig', $form);
    }

    private function column(string $id, Project $project, BoardColumn $backlog): BoardColumn
    {
        if ('' === $id) {
            return $backlog;
        }
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        return ($this->findBoardColumn)(new FindBoardColumnCommand($id, $project))
            ?? throw $this->createNotFoundException();
    }
}
