<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\RunInboxActionCommand;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\RunInboxActionFormType;
use App\Module\Inbox\Form\RunInboxActionRequest;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Inbox\Service\InboxActionFeedback;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxReturnTargetResolver;
use App\Module\Inbox\View\InboxActionVerb;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(InboxItemVoter::ANSWER, subject: 'item')]
#[Route(
    '/projects/{projectId}/inbox/items/{itemId}/actions/{kind}',
    name: 'app_inbox_item_action',
    requirements: [
        'itemId' => Requirement::UUID,
        'kind' => new EnumRequirement([InboxActionKind::ResumeRun, InboxActionKind::RetryCard, InboxActionKind::SendAgent, InboxActionKind::StartSession, InboxActionKind::RunCommand, InboxActionKind::AddEntry, InboxActionKind::Dismiss]),
    ],
    methods: ['POST'],
)]
final class RunInboxActionController extends AppController
{
    public function __construct(
        private readonly RunInboxActionHandler $runAction,
        private readonly FormFactoryInterface $formFactory,
        private readonly InboxAvailability $inbox,
        private readonly InboxReturnTargetResolver $returnTargets,
        private readonly InboxActionFeedback $feedback,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        InboxActionKind $kind,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(itemId, projectId)')] InboxItem $item,
    ): Response {
        $this->inbox->requireEnabled();
        $return = $this->returnTargets->resolve($request, $item);
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in user.');

        $data = new RunInboxActionRequest();
        $form = $this->formFactory->createNamed(RunInboxActionFormType::nameFor($item), RunInboxActionFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->runAction)(new RunInboxActionCommand($item, $kind, $data->note ?? '', $user, '' === ($data->branch ?? '') ? null : $data->branch, [] === $data->params ? null : $data->params, $data->runDigest, $data->sessionPrompt));
                // Read again, because a saved response can move the item to the completed queue.
                $saved = $this->returnTargets->resolve($request, $item, $return);
                $verb = match ($kind) {
                    InboxActionKind::ResumeRun => InboxActionVerb::Resumed,
                    InboxActionKind::RetryCard => InboxActionVerb::Retried,
                    InboxActionKind::SendAgent => InboxActionVerb::AgentSent,
                    InboxActionKind::StartSession => InboxActionVerb::SessionStarted,
                    InboxActionKind::RunCommand => InboxActionVerb::CommandQueued,
                    InboxActionKind::AddEntry => InboxActionVerb::EntryQueued,
                    default => InboxActionVerb::Declined,
                };
                $this->feedback->saved($saved, $item, $verb);

                return $this->redirectToRoute($saved->route, $saved->routeParameters);
            } catch (DomainErrors $e) {
                foreach ($e->errors as $field => $translationKey) {
                    $target = 'note' === $field ? $form->get('note') : $form;
                    $target->addError(new FormError($this->translator->trans($translationKey)));
                }
            }
        }

        return $this->forward($return->controller, [
            ...$return->attributes,
            ShowInboxController::REFUSED_FORM => $form->createView(),
        ], $return->query)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
