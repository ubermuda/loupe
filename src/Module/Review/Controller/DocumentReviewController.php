<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review',
    name: 'app_document_review',
    methods: ['GET'],
)]
final class DocumentReviewController extends AppController
{
    public function __construct(
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CommentRepository $comments,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
    ): Response {
        $version = $this->documentVersions->findLatest($document);
        $comments = $this->comments->findByVersion($version);

        $addCommentForm = $this->createForm(AddCommentFormType::class, new AddCommentRequest(), [
            'action' => $this->generateUrl('app_comment_add', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]),
            'method' => 'POST',
        ]);

        return $this->render('@Review/review.html.twig', [
            'document' => $document,
            'version' => $version,
            'comments' => $comments,
            'orphanedCount' => count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
            'addCommentForm' => $addCommentForm,
        ]);
    }
}
