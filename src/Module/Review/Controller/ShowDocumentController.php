<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ShowDocumentCommand;
use App\Module\Review\Command\ShowDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Form\AddCommentFormType;
use App\Module\Review\Form\AddCommentRequest;
use App\Module\Review\Form\SelectDecisionOptionFormType;
use App\Module\Review\Form\SelectDecisionOptionRequest;
use App\Module\Review\Form\StrikePassageFormType;
use App\Module\Review\Form\StrikePassageRequest;
use App\Module\Review\Form\SuggestRewordingFormType;
use App\Module\Review\Form\SuggestRewordingRequest;
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
final class ShowDocumentController extends AppController
{
    public function __construct(
        private readonly ShowDocumentHandler $showDocument,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        ?int $versionNumber = null,
    ): Response {
        $reader = $this->getUser();
        $view = ($this->showDocument)(new ShowDocumentCommand(
            $document,
            $versionNumber,
            $reader instanceof User ? $reader : null,
        ));

        $routeParameters = [
            'projectId' => (string) $project->id,
            'documentId' => (string) $document->id,
        ];

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

        // Stamped with the version whose options are being rendered, so a
        // submission that arrives after a revision can be told apart from one
        // that describes the current list.
        $selectDecisionForm = $this->createForm(SelectDecisionOptionFormType::class, new SelectDecisionOptionRequest(versionNumber: $view->version->versionNumber), [
            'action' => $this->generateUrl('app_document_decision_select', $routeParameters),
            'method' => 'POST',
        ]);

        return $this->render('@Review/show_document.html.twig', [
            'document' => $view->document,
            'version' => $view->version,
            'versions' => $view->versions,
            // The shared page shell reads these to decide whether it is showing a
            // document or a comparison of two; here it is always the document.
            'diffMode' => false,
            'diffFromVersion' => null,
            'diffView' => null,
            'diffChangeCount' => null,
            'diffCommenting' => false,
            'readOnly' => $view->readOnly,
            'comments' => $view->comments,
            'headings' => $view->headings,
            'orphanedCount' => $view->orphanedCount,
            'addCommentForm' => $addCommentForm,
            'suggestRewordingForm' => $suggestRewordingForm,
            'strikePassageForm' => $strikePassageForm,
            'selectDecisionForm' => $selectDecisionForm,
            'decisions' => $view->decisions,
            'decisionMarkedHtml' => $view->decisionMarkedHtml,
            'lastSeenVersionNumber' => $view->lastSeenVersionNumber,
        ]);
    }
}
