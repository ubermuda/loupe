<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\DiffDocumentVersionsCommand;
use App\Module\Review\Command\DiffDocumentVersionsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Form\StrikePassageFormType;
use App\Module\Review\Form\StrikePassageRequest;
use App\Module\Review\Form\SuggestRewordingFormType;
use App\Module\Review\Form\SuggestRewordingRequest;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\ValueObject\DiffView;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Re-reviewing a revised document otherwise means reading all of it again. This
// shows the delta between two versions instead: the review page with the
// document pane holding the diff, so the version list, the references and the
// reading measure are the ones the reviewer already knows.
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
        private readonly DiffDocumentVersionsHandler $diffDocumentVersions,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        int $fromVersionNumber,
        int $toVersionNumber,
    ): Response {
        if ($fromVersionNumber >= $toVersionNumber) {
            throw $this->createNotFoundException('A diff runs from an earlier version to a later one.');
        }

        $view = ($this->diffDocumentVersions)(new DiffDocumentVersionsCommand(
            document: $document,
            fromVersionNumber: $fromVersionNumber,
            toVersionNumber: $toVersionNumber,
            view: DiffView::fromRequestValue($request->query->all()['view'] ?? null),
        ));

        $routeParameters = [
            'projectId' => (string) $project->id,
            'documentId' => (string) $document->id,
        ];

        // The same three forms the review page posts, on the same three routes.
        // A comment made here is an ordinary comment on the current version, so
        // nothing downstream learns it came from a diff.
        $addCommentForm = null;
        $suggestRewordingForm = null;
        $strikePassageForm = null;
        if ($view->commentingEnabled) {
            $addCommentForm = $this->createForm(AddCommentFormType::class, new AddCommentRequest(), [
                'action' => $this->generateUrl('app_comment_add', $routeParameters),
                'method' => 'POST',
            ]);

            $suggestRewordingForm = $this->createForm(SuggestRewordingFormType::class, new SuggestRewordingRequest(), [
                'action' => $this->generateUrl('app_comment_suggest', $routeParameters),
                'method' => 'POST',
            ]);

            $strikePassageForm = $this->createForm(StrikePassageFormType::class, new StrikePassageRequest(), [
                'action' => $this->generateUrl('app_comment_strike', $routeParameters),
                'method' => 'POST',
            ]);
        }

        return $this->render('@Review/diff_document_versions.html.twig', [
            'document' => $document,
            'version' => $view->version,
            'versions' => $view->versions,
            'diffMode' => true,
            'diffView' => $view->view,
            'diff' => $view->diff,
            'renderedDiff' => $view->renderedDiff,
            'diffChangeCount' => $view->changeCount,
            'diffRefusal' => $view->diffRefusal,
            'diffFromVersion' => $fromVersionNumber,
            // The verdict and the decision controls describe one version, and a
            // diff describes two, so they stay off here. Commenting is separate:
            // it anchors to the newer side when that side is the current version.
            'readOnly' => true,
            'diffCommenting' => $view->commentingEnabled,
            'comments' => $view->comments,
            'orphanedCount' => $view->orphanedCount,
            'addCommentForm' => $addCommentForm,
            'suggestRewordingForm' => $suggestRewordingForm,
            'strikePassageForm' => $strikePassageForm,
        ]);
    }
}
