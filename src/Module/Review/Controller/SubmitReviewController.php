<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\SubmitReviewFormType;
use App\Module\Review\Form\SubmitReviewRequest;
use App\Module\Review\Security\DocumentVoter;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('submit-review')]
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/submit',
    name: 'app_document_review_submit',
    methods: ['POST'],
)]
final class SubmitReviewController extends AppController
{
    public function __construct(
        private readonly SubmitReviewHandler $submitReviewHandler,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $data = new SubmitReviewRequest();
        $form = $this->createForm(SubmitReviewFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('review.document.flash.verdict_invalid'));

            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        try {
            $review = ($this->submitReviewHandler)(new SubmitReviewCommand(
                reviewer: $user,
                document: $document,
                verdict: $data->verdict ?: throw new \LogicException('verdict required after validation'),
            ));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        $this->addFlash('success', $this->translator->trans('review.document.flash.verdict_submitted'));

        $this->logger->info('review.document.verdict_submitted', [
            'documentId' => (string) $document->id,
            'verdict' => $review->verdict->value,
            'reviewerId' => (string) $user->id,
        ]);

        return $this->redirectToRoute('app_document_review', [
            'projectId' => (string) $project->id,
            'documentId' => (string) $document->id,
        ]);
    }
}
