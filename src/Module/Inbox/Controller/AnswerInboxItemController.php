<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\AnswerInboxItemFormType;
use App\Module\Inbox\Form\AnswerInboxItemRequest;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxReturnTargetResolver;
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
    '/projects/{projectId}/inbox/items/{itemId}/answer',
    name: 'app_inbox_item_answer',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class AnswerInboxItemController extends AppController
{
    public function __construct(
        private readonly AnswerInboxItemHandler $answerItem,
        private readonly FormFactoryInterface $formFactory,
        private readonly InboxAvailability $inbox,
        private readonly InboxReturnTargetResolver $returnTargets,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(itemId, projectId)')] InboxItem $item,
    ): Response {
        $this->inbox->requireEnabled();
        $return = $this->returnTargets->resolve($request, $item);

        $data = new AnswerInboxItemRequest();
        $form = $this->formFactory->createNamed(AnswerInboxItemFormType::nameFor($item), AnswerInboxItemFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->answerItem)(new AnswerInboxItemCommand($item, $data->selectedOptions ?? '', $data->answerText ?? ''));
                $this->addFlash('success', $this->translator->trans('inbox.flash.answered', ['%number%' => $item->number]));

                return $this->redirectToRoute($return->route, $return->routeParameters);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->forward($return->controller, [
            ...$return->attributes,
            ShowInboxController::REFUSED_FORM => $form->createView(),
        ], $return->query)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
