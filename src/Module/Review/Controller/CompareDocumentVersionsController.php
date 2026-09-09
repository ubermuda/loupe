<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// The history page's compare picker posts here. The diff route carries its two
// versions as path segments, which a GET form cannot build, so this reads them
// as query parameters and sends the reader on.
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/compare',
    name: 'app_document_review_compare',
    methods: ['GET'],
)]
final class CompareDocumentVersionsController extends AppController
{
    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        string $projectId,
    ): Response {
        $query = $request->query->all();
        $from = $this->versionNumber($query['from'] ?? null);
        $to = $this->versionNumber($query['to'] ?? null);

        $routeParameters = ['projectId' => $projectId, 'documentId' => (string) $document->id];

        // A pair chosen the wrong way round describes the same comparison, so it
        // is answered rather than refused. One version against itself, and any
        // number the picker cannot have produced, has no diff to show.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from < 1 || $from === $to) {
            return $this->redirectToRoute('app_document_review_history', $routeParameters);
        }

        return $this->redirectToRoute('app_document_review_diff', [
            ...$routeParameters,
            'fromVersionNumber' => $from,
            'toVersionNumber' => $to,
        ]);
    }

    /** 0 for anything that does not name a version, which the caller sends back to the history. */
    private function versionNumber(mixed $raw): int
    {
        return is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    }
}
