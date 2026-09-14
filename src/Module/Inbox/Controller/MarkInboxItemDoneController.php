<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\MarkInboxItemDoneFormType;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Inbox\Service\InboxAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(InboxItemVoter::ANSWER, subject: 'item')]
#[Route(
    '/projects/{projectId}/inbox/items/{itemId}/done',
    name: 'app_inbox_item_done',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class MarkInboxItemDoneController extends AppController
{
    public function __construct(
        private readonly MarkInboxItemDoneHandler $markDone,
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

        $form = $this->formFactory->createNamed(MarkInboxItemDoneFormType::nameFor($item), MarkInboxItemDoneFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->markDone)(new MarkInboxItemDoneCommand($item));
                $this->addFlash('success', $this->translator->trans('inbox.flash.done', ['%number%' => $item->number]));

                return $this->redirectToRoute('app_project_inbox', ['id' => (string) $item->project->id]);
            } catch (DomainErrors $e) {
                // The form has no field, so every refusal belongs to the form itself.
                foreach ($e->errors as $translationKey) {
                    $form->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->forward(ShowInboxController::class, [
            'id' => (string) $item->project->id,
            'project' => $item->project,
            ShowInboxController::REFUSED_FORM => $form->createView(),
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
