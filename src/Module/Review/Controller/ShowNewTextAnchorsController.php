<?php

declare(strict_types=1);

namespace App\Module\Review\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ShowNewTextAnchorsCommand;
use App\Module\Review\Command\ShowNewTextAnchorsHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Security\DocumentVoter;
use App\Module\Review\ValueObject\Anchor;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The passages one version added since the version before it, fetched when the reader turns the highlight on. */
#[IsGranted(DocumentVoter::VIEW, subject: 'document')]
#[Route(
    '/projects/{projectId}/documents/{documentId}/review/new-text/{versionNumber}',
    name: 'app_document_new_text',
    requirements: ['versionNumber' => '\d+'],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowNewTextAnchorsController extends AppController
{
    public function __construct(
        private readonly ShowNewTextAnchorsHandler $showNewTextAnchors,
    ) {
    }

    public function __invoke(
        #[MapEntity(mapping: ['projectId' => 'id'])] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(documentId, projectId)')] Document $document,
        int $versionNumber,
    ): JsonResponse {
        $result = ($this->showNewTextAnchors)(new ShowNewTextAnchorsCommand($document, $versionNumber));

        return new JsonResponse([
            'anchors' => array_map(
                static fn (Anchor $anchor): array => ['quote' => $anchor->quote, 'prefix' => $anchor->prefix, 'suffix' => $anchor->suffix],
                $result->anchors,
            ),
            'reason' => $result->reason?->value,
        ]);
    }
}
