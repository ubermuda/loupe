<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\Service\MarkdownDiffer;
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
// A revision carries only the still-open comments forward, so a thread resolved
// before it stays on the version it was written on. Without a way to render that
// version the whole discussion becomes unreachable the moment the document is
// revised. The unnumbered route above keeps meaning "latest", so every existing
// link to it is unaffected.
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/versions/{versionNumber}',
    name: 'app_document_review_version',
    requirements: ['versionNumber' => '\d+'],
    methods: ['GET'],
)]
// Re-reviewing a revised document otherwise means reading all of it again. This
// route shows the delta between any two versions instead, in place of the
// document rather than beside it.
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/diff/{fromVersionNumber}/{toVersionNumber}',
    name: 'app_document_review_diff',
    requirements: ['fromVersionNumber' => '\d+', 'toVersionNumber' => '\d+'],
    methods: ['GET'],
)]
final class ShowDocumentController extends AppController
{
    public function __construct(
        private readonly DocumentVersionRepository $documentVersions,
        private readonly CommentRepository $comments,
        private readonly MarkdownDiffer $markdownDiffer,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        ?int $versionNumber = null,
        ?int $fromVersionNumber = null,
        ?int $toVersionNumber = null,
    ): Response {
        $latest = $this->documentVersions->findLatest($document);

        $diff = null;
        if (null !== $fromVersionNumber && null !== $toVersionNumber) {
            if ($fromVersionNumber >= $toVersionNumber) {
                throw $this->createNotFoundException('A diff runs from an earlier version to a later one.');
            }
            $version = $this->version($document, $toVersionNumber);
            $diff = $this->markdownDiffer->diff(
                $this->version($document, $fromVersionNumber)->markdownSource,
                $version->markdownSource,
            );
        } else {
            $version = null === $versionNumber ? $latest : $this->version($document, $versionNumber);
        }

        // Every write on this page targets the current version: the composer posts
        // a comment onto whatever is latest, and the verdict applies to the document
        // as it stands. An older version is therefore rendered as a read-only record
        // of what was discussed then. A diff is read-only for a second reason — the
        // document pane holds diff markup, so its textContent is no longer
        // DocumentVersion::plainText() and anchoring has no basis to resolve against.
        $isLatest = $version->versionNumber === $latest->versionNumber;
        $comments = $this->comments->findByVersion($version);

        $addCommentForm = $this->createForm(AddCommentFormType::class, new AddCommentRequest(), [
            'action' => $this->generateUrl('app_comment_add', [
                'projectId' => (string) $project->id,
                'documentId' => (string) $document->id,
            ]),
            'method' => 'POST',
        ]);

        return $this->render('@Review/show_document.html.twig', [
            'document' => $document,
            'version' => $version,
            'versions' => $this->documentVersions->findAllMetaByDocument($document),
            'diff' => $diff,
            'diffFromVersion' => $fromVersionNumber,
            'readOnly' => null !== $diff || !$isLatest,
            'comments' => $comments,
            'orphanedCount' => count(array_filter($comments, static fn (Comment $c) => $c->orphaned)),
            'addCommentForm' => $addCommentForm,
        ]);
    }

    private function version(Document $document, int $versionNumber): DocumentVersion
    {
        return $this->documentVersions->findByNumber($document, $versionNumber)
            ?? throw $this->createNotFoundException(sprintf('Document has no version %d.', $versionNumber));
    }
}
