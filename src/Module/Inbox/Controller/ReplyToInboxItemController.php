<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\ReplyToInboxItemCommand;
use App\Module\Inbox\Command\ReplyToInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\ReplyToInboxItemFormType;
use App\Module\Inbox\Form\ReplyToInboxItemRequest;
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

#[IsGranted(InboxItemVoter::REPLY, subject: 'item')]
#[Route(
    '/projects/{projectId}/inbox/items/{itemId}/reply',
    name: 'app_inbox_item_reply',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ReplyToInboxItemController extends AppController
{
    public function __construct(
        private readonly ReplyToInboxItemHandler $reply,
        private readonly FormFactoryInterface $formFactory,
        private readonly InboxAvailability $inbox,
        private readonly InboxReturnTargetResolver $returnTargets,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, #[MapEntity(expr: 'repository.findOneByIdAndProjectId(itemId, projectId)')] InboxItem $item): Response
    {
        $this->inbox->requireEnabled();
        $return = $this->returnTargets->resolve($request, $item);
        $data = new ReplyToInboxItemRequest();
        $form = $this->formFactory->createNamed(ReplyToInboxItemFormType::nameFor($item), ReplyToInboxItemFormType::class, $data);
        $form->handleRequest($request);
        $author = $this->getUser();
        if (!$author instanceof User) {
            throw new \LogicException('An inbox reply requires an authenticated owner.');
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->reply)(new ReplyToInboxItemCommand($item, $author, $data->body ?? '', $data->submissionId ?? ''));
                $this->addFlash('success', $this->translator->trans('inbox.reply.added'));

                return $this->redirectToRoute($return->route, $return->routeParameters);
            } catch (DomainErrors $error) {
                $this->applyDomainErrors($form, $error);
            }
        }

        return $this->forward($return->controller, [
            ...$return->attributes,
            ShowInboxController::REFUSED_FORM => $form->createView(),
        ], $return->query)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
