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
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\CreateCardFormType;
use App\Module\Board\Form\CreateCardRequest;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\SiteReview\Command\FindSiteReviewCommentCommand;
use App\Module\SiteReview\Command\FindSiteReviewCommentHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Security\SiteReviewCommentVoter;
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
        private readonly BoardAvailability $board,
        private readonly FindSiteReviewCommentHandler $findSiteReviewComment,
        private readonly ShowCardPlacementHandler $showPlacement,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        // `project` holds the raw id here, because the route aliases `id` to it.
        #[MapEntity(expr: 'repository.findDefaultForProjectId(project)')] BoardColumn $defaultColumn,
    ): Response {
        $this->board->requireEnabled();

        $feedbackId = $request->query->getString('feedback');
        $feedback = $this->feedback($feedbackId, $project);
        $column = $this->column($request->query->getString('column'), $project, $defaultColumn);

        $data = new CreateCardRequest(column: $column);
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
                    type: $data->type ?? CardType::Feature,
                    column: $data->column,
                    // A person filled this form in, whatever an agent may later do to the card.
                    reporter: CardReporter::Human,
                    pullRequestUrls: CreateCardRequest::toUrlList($data->pullRequestUrls),
                    siteReviewComment: $feedback,
                    relatedCards: $data->linkInputs(),
                ));
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);

                return $this->renderFormResponse('@Board/create_card.html.twig', $form, ['feedback' => $feedback]);
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

        return $this->renderFormResponse('@Board/create_card.html.twig', $form, ['feedback' => $feedback]);
    }

    private function feedback(string $id, Project $project): ?SiteReviewComment
    {
        if ('' === $id) {
            return null;
        }
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $comment = ($this->findSiteReviewComment)(new FindSiteReviewCommentCommand($id, $project))
            ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(SiteReviewCommentVoter::ATTACH, $comment);

        return $comment;
    }

    private function column(string $id, Project $project, BoardColumn $defaultColumn): BoardColumn
    {
        if ('' === $id) {
            return $defaultColumn;
        }
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        return ($this->findBoardColumn)(new FindBoardColumnCommand($id, $project))
            ?? throw $this->createNotFoundException();
    }
}
