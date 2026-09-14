<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\DeclineInboxItemFormType;
use App\Module\Inbox\Form\DeclineInboxItemRequest;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Inbox\Service\InboxAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(InboxItemVoter::ANSWER, subject: 'item')]
#[Route(
    '/projects/{projectId}/inbox/items/{itemId}/decline',
    name: 'app_inbox_item_decline',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class DeclineInboxItemController extends AppController
{
    public function __construct(
        private readonly DeclineInboxItemHandler $declineItem,
        private readonly FormFactoryInterface $formFactory,
        private readonly InboxAvailability $inbox,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(itemId, projectId)')] InboxItem $item,
    ): Response {
        $this->inbox->requireEnabled();

        $data = new DeclineInboxItemRequest();
        $form = $this->formFactory->createNamed(DeclineInboxItemFormType::nameFor($item), DeclineInboxItemFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->declineItem)(new DeclineInboxItemCommand($item, $data->closeNote ?? ''));
                $this->addFlash('success', $this->translator->trans('inbox.flash.declined', ['%number%' => $item->number]));

                return $this->redirectToRoute('app_project_inbox', ['id' => (string) $item->project->id]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward(ShowInboxController::class, [
            'id' => (string) $item->project->id,
            'project' => $item->project,
            ShowInboxController::REFUSED_FORM => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
