<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
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
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $title = $request->request->getString('title', 'E2E Test Document');
        $markdown = $request->request->getString('markdown', '# Hello World');

        $document = ($this->createDocument)(new CreateDocumentCommand($user, $title, $markdown));

        return $this->json([
            'documentId' => (string) $document->id,
        ], JsonResponse::HTTP_CREATED);
    }
}
