<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;
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
        $verdict = Verdict::tryFrom($request->request->getString('verdict'));

        if (null === $verdict) {
            $this->addFlash('danger', $this->translator->trans('review.document.flash.verdict_invalid'));

            return $this->redirectToRoute('app_document_review', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]);
        }

        $user = $this->getUser();
        assert($user instanceof User);

        ($this->submitReviewHandler)(new SubmitReviewCommand(
            reviewer: $user,
            document: $document,
            verdict: $verdict,
        ));

        $this->addFlash('success', $this->translator->trans('review.document.flash.verdict_submitted'));

        $this->logger->info('review.document.verdict_submitted', [
            'documentId' => (string) $document->id,
            'verdict' => $verdict->value,
            'reviewerId' => (string) $user->id,
        ]);

        return $this->redirectToRoute('app_document_review', [
            'projectId' => (string) $project->id,
            'documentId' => (string) $document->id,
        ]);
    }
}
