<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly ProjectRepository $projects,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Route is behind the ROLE_USER catch-all');
        }

        $title = $request->request->getString('title', 'E2E Test Document');
        $markdown = $request->request->getString('markdown', '# Hello World');

        $project = $this->projects->findOneByOwnerAndName($user, 'e2e-harness');
        if (null === $project) {
            $project = new Project($user, 'e2e-harness');
            $this->em->persist($project);
            $this->em->flush();
        }

        $document = ($this->createDocument)(new CreateDocumentCommand($project, $title, $markdown));

        return $this->json([
            'documentId' => (string) $document->id,
            'projectId' => (string) $project->id,
        ], JsonResponse::HTTP_CREATED);
    }
}
