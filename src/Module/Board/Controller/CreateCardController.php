<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Form\CreateCardFormType;
use App\Module\Board\Form\CreateCardRequest;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $this->board->requireEnabled();

        $data = new CreateCardRequest();
        $form = $this->createForm(CreateCardFormType::class, $data);
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
                    priority: $data->priority ?? CardPriority::Medium,
                    status: $data->status ?? CardStatus::Backlog,
                    // A person filled this form in, whatever an agent may later do to the card.
                    origin: CardOrigin::Human,
                    pullRequestUrls: CreateCardRequest::toUrlList($data->pullRequestUrls),
                ));

                return $this->redirectToRoute('app_board_card', [
                    'projectId' => (string) $project->id,
                    'cardId' => (string) $card->id,
                ]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Board/create_card.html.twig', $form);
    }
}
