<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\SubmitInboxPullRequestReviewCommand;
use App\Module\Inbox\Command\SubmitInboxPullRequestReviewHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\SubmitInboxPullRequestReviewFormType;
use App\Module\Inbox\Form\SubmitInboxPullRequestReviewRequest;
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
    '/projects/{projectId}/inbox/items/{itemId}/review-pull-request',
    name: 'app_inbox_item_review_pull_request',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class SubmitInboxPullRequestReviewController extends AppController
{
    public function __construct(
        private readonly SubmitInboxPullRequestReviewHandler $submitReview,
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
        $data = new SubmitInboxPullRequestReviewRequest();
        $form = $this->formFactory->createNamed(SubmitInboxPullRequestReviewFormType::nameFor($item), SubmitInboxPullRequestReviewFormType::class, $data);
        $form->handleRequest($request);
        $reviewer = $this->getUser();
        if (!$reviewer instanceof User) {
            throw new \LogicException('An inbox reviewer must be an authenticated user.');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->submitReview)(new SubmitInboxPullRequestReviewCommand($item, $reviewer, $data->verdict ?? '', $data->expectedUrl ?? '', $data->note));
                $this->addFlash('success', $this->translator->trans('inbox.review.submitted'));

                // Read again, because a saved response can move the item to the completed queue.
                $saved = $this->returnTargets->resolve($request, $item);

                return $this->redirectToRoute($saved->route, $saved->routeParameters);
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
