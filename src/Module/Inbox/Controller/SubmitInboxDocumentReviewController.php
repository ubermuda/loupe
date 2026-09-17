<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\SubmitInboxDocumentReviewCommand;
use App\Module\Inbox\Command\SubmitInboxDocumentReviewHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Form\SubmitInboxDocumentReviewFormType;
use App\Module\Inbox\Security\InboxItemVoter;
use App\Module\Inbox\Service\InboxAvailability;
use App\Module\Inbox\Service\InboxReturnTargetResolver;
use App\Module\Review\Form\SubmitReviewRequest;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(InboxItemVoter::REVIEW_DOCUMENT, subject: 'item')]
#[Route(
    '/projects/{projectId}/inbox/items/{itemId}/review-document',
    name: 'app_inbox_item_review_document',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class SubmitInboxDocumentReviewController extends AppController
{
    public function __construct(
        private readonly SubmitInboxDocumentReviewHandler $submitReview,
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
        $data = new SubmitReviewRequest();
        $form = $this->formFactory->createNamed(SubmitInboxDocumentReviewFormType::nameFor($item), SubmitInboxDocumentReviewFormType::class, $data);
        $form->handleRequest($request);
        $reviewer = $this->getUser();
        if (!$reviewer instanceof User) {
            throw new \LogicException('An inbox reviewer must be an authenticated user.');
        }

        if ($form->isSubmitted() && $form->isValid() && null !== $data->versionNumber) {
            try {
                ($this->submitReview)(new SubmitInboxDocumentReviewCommand($item, $reviewer, $data->verdict ?? '', $data->versionNumber, $data->expectedReviewId, $data->note));
                $this->addFlash('success', $this->translator->trans('inbox.review.submitted'));

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
