<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ShowDecisionSummaryCommand;
use App\Module\Review\Command\ShowDecisionSummaryHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/** The decision summary of one version, fetched when a live update says an answer changed. */
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/decisions/summary',
    name: 'app_document_decision_summary',
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowDecisionSummaryController extends AppController
{
    public function __construct(
        private readonly ShowDecisionSummaryHandler $showDecisionSummary,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        #[MapQueryParameter] int $versionNumber,
    ): Response {
        $summary = ($this->showDecisionSummary)(new ShowDecisionSummaryCommand($document, $versionNumber));

        return new Response($this->renderView('@Review/_decision_summary.stream.html.twig', [
            'page' => $document->id.'/'.$versionNumber,
            'rows' => $summary->rows,
            'answeredCount' => $summary->answeredCount,
        ]), Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
