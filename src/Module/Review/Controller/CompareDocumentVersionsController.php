<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Command\CountDocumentVersionsCommand;
use App\Module\Review\Command\CountDocumentVersionsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The compare pickers on the history page and in the review page's versions
// panel post here. The diff route carries its two versions as path segments,
// which a GET form cannot build, so this reads them as query parameters and
// sends the reader on.
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/compare',
    name: 'app_document_review_compare',
    methods: ['GET'],
)]
final class CompareDocumentVersionsController extends AppController
{
    public function __construct(
        private readonly CountDocumentVersionsHandler $countDocumentVersions,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        string $projectId,
    ): Response {
        $query = $request->query->all();
        $from = $this->versionNumber($query['from'] ?? null);
        $to = $this->versionNumber($query['to'] ?? null);

        // A pair chosen the wrong way round describes the same comparison, so it
        // is answered rather than refused.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $routeParameters = ['projectId' => $projectId, 'documentId' => (string) $document->id];

        $expected = $from === $to ? 1 : 2;
        $found = ($this->countDocumentVersions)(new CountDocumentVersionsCommand($document, [$from, $to]));

        if ($expected !== $found) {
            return $this->redirectToRoute('app_document_review_history', $routeParameters);
        }

        // The picker in the versions panel is used while a comparison is already
        // on screen, so the reader keeps the view they chose. The diff route
        // validates the value, and an absent one is the rendered view.
        $view = $query['view'] ?? null;

        return $this->redirectToRoute('app_document_review_diff', [
            ...$routeParameters,
            'fromVersionNumber' => $from,
            'toVersionNumber' => $to,
            ...(is_string($view) && '' !== $view ? ['view' => $view] : []),
        ]);
    }

    /** 0 for anything that does not name a version, which no document has. */
    private function versionNumber(mixed $raw): int
    {
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    }
}
