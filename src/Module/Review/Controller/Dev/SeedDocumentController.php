<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\EnsureHarnessProjectCommand;
use App\Module\Project\Command\EnsureHarnessProjectHandler;
use App\Module\Review\Command\ArchiveDocumentCommand;
use App\Module\Review\Command\ArchiveDocumentHandler;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only endpoint that creates a seeded document for the authenticated user and returns its id.
 * Used exclusively by Playwright e2e tests — not available in production (When('dev')).
 */
#[Route(
    '/dev/seed/document',
    name: 'app_dev_seed_document',
    methods: ['POST'],
)]
#[When('dev')]
final class SeedDocumentController extends AppController
{
    public function __construct(
        private readonly CreateDocumentHandler $createDocument,
        private readonly ArchiveDocumentHandler $archiveDocument,
        private readonly ReviseDocumentHandler $reviseDocument,
        private readonly EnsureHarnessProjectHandler $ensureHarnessProject,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $title = $request->request->getString('title', 'E2E Test Document');
        $markdown = $request->request->getString('markdown', '# Hello World');

        $project = ($this->ensureHarnessProject)(new EnsureHarnessProjectCommand($user, 'e2e-harness'));

        $document = ($this->createDocument)(new CreateDocumentCommand(project: $project, title: $title, markdown: $markdown));

        // `revisions` is a JSON array of Markdown sources, applied in order: a
        // browser test has no other way to give a document the history a diff needs.
        foreach ($this->revisions($request->request->getString('revisions')) as $index => $markdownRevision) {
            ($this->reviseDocument)(new ReviseDocumentCommand(
                document: $document,
                markdown: $markdownRevision,
                description: \sprintf('Seeded revision %d.', $index + 1),
            ));
        }

        // A reason can only be set through MCP, which a browser test has no way
        // to call, so the seed archives with one on request. This is a test
        // fixture, not a second way to state a reason: the app itself still
        // offers no field for it.
        $archiveReason = $request->request->getString('archiveReason');
        if ('' !== $archiveReason) {
            ($this->archiveDocument)(new ArchiveDocumentCommand($document, $archiveReason));
        }

        return $this->json([
            'documentId' => (string) $document->id,
            'projectId' => (string) $project->id,
        ], JsonResponse::HTTP_CREATED);
    }

    /** @return list<string> */
    private function revisions(string $encoded): array
    {
        if ('' === $encoded) {
            return [];
        }

        $decoded = json_decode($encoded, true, flags: \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('revisions must be a JSON array of Markdown sources.');
        }

        return array_values(array_map(strval(...), $decoded));
    }
}
