<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\MarkdownDiffer;
use App\Module\Review\ValueObject\DiffRefusal;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Re-reviewing a revised document otherwise means reading all of it again. This
// shows the delta between two versions instead, in place of the document rather
// than beside it.
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/diff/{fromVersionNumber}/{toVersionNumber}',
    name: 'app_document_review_diff',
    requirements: ['fromVersionNumber' => '\d+', 'toVersionNumber' => '\d+'],
    methods: ['GET'],
)]
final class DiffDocumentVersionsController extends AppController
{
    public function __construct(
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CommentRepository $comments,
        private readonly MarkdownDiffer $markdownDiffer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        int $fromVersionNumber,
        int $toVersionNumber,
    ): Response {
        if ($fromVersionNumber >= $toVersionNumber) {
            throw $this->createNotFoundException('A diff runs from an earlier version to a later one.');
        }

        $version = $this->version($document, $toVersionNumber);
        $result = $this->markdownDiffer->diff(
            $this->version($document, $fromVersionNumber)->markdownSource,
            $version->markdownSource,
        );

        $diff = null;
        $diffRefusal = null;
        if ($result instanceof DiffRefusal) {
            $diffRefusal = $result;
            $this->logger->info('review.document.diff_refused', [
                'documentId' => (string) $document->id,
                'from' => $fromVersionNumber,
                'to' => $toVersionNumber,
                'reason' => $result->value,
            ]);
        } else {
            $diff = $result;
        }

        // A diff never accepts a comment or a verdict: the pane holds diff markup
        // rather than the document, so anchoring has no text basis to resolve
        // against. That is the same `readOnly` the review page uses for an earlier
        // version, and it is why no comment form is built here.
        $comments = $this->comments->findByVersion($version);

        return $this->render('@Review/diff_document_versions.html.twig', [
            'document' => $document,
            'version' => $version,
            'versions' => $this->documentVersions->findAllMetaByDocument($document),
            'diffMode' => true,
            'diff' => $diff,
            'diffRefusal' => $diffRefusal,
            'diffFromVersion' => $fromVersionNumber,
            'readOnly' => true,
            'comments' => $comments,
            'orphanedCount' => count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
        ]);
    }

    private function version(Document $document, int $versionNumber): DocumentVersion
    {
        return $this->documentVersions->findByNumber($document, $versionNumber)
            ?? throw $this->createNotFoundException(sprintf('Document has no version %d.', $versionNumber));
    }
}
