<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Form\CreateCardFormType;
use App\Module\Board\Form\UpdateCardRequest;
use App\Module\Board\Security\CardVoter;
use App\Module\Board\Service\BoardAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/edit',
    name: 'app_board_card_edit',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['GET', 'POST'],
)]
final class EditCardController extends AppController
{
    public function __construct(
        private readonly UpdateCardHandler $updateCard,
        private readonly BoardAvailability $board,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $this->board->requireEnabled();

        // The edit form reuses the create form; UpdateCardRequest only adds the
        // factory that pre-fills it from the card.
        $data = UpdateCardRequest::fromCard($card);
        $form = $this->createForm(CreateCardFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = trim($data->title ?? '');
            if ('' === $title) {
                throw new \LogicException('title required after validation');
            }

            try {
                ($this->updateCard)(new UpdateCardCommand(
                    card: $card,
                    title: $title,
                    body: $data->body ?? '',
                    type: $data->type,
                    priority: $data->priority,
                    status: $data->status,
                    // Replace semantics: an emptied textarea clears every link.
                    pullRequestUrls: UpdateCardRequest::toUrlList($data->pullRequestUrls),
                ));

                return $this->redirectToRoute('app_board_card', [
                    'projectId' => (string) $card->project->id,
                    'cardId' => (string) $card->id,
                ]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Board/edit_card.html.twig', $form, ['card' => $card]);
    }
}
