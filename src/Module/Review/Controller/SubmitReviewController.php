<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Security\DocumentVoter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('submit-review')]
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/documents/{id:document}/review/submit',
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

    public function __invoke(Request $request, Document $document): Response
    {
        $verdict = Verdict::tryFrom($request->request->getString('verdict'));

        if (null === $verdict) {
            $this->addFlash('danger', $this->translator->trans('review.document.flash.verdict_invalid'));

            return $this->redirectToRoute('app_document_review', ['id' => (string) $document->id]);
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

        return $this->redirectToRoute('app_document_review', ['id' => (string) $document->id]);
    }
}
