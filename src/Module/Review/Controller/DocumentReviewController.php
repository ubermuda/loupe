<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/documents/{id:document}/review',
    name: 'app_document_review',
    methods: ['GET'],
)]
final class DocumentReviewController extends AppController
{
    public function __construct(
        private readonly CommentRepository $comments,
    ) {
    }

    public function __invoke(Document $document): Response
    {
        $version = $document->currentVersion();
        $comments = $this->comments->findByVersion($version);

        return $this->render('review/review.html.twig', [
            'document' => $document,
            'version' => $version,
            'comments' => $comments,
            'orphanedCount' => count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
        ]);
    }
}
