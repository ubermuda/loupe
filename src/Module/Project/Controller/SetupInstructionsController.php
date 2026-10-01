<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/setup.md',
    name: 'app_setup_instructions',
    methods: ['GET'],
)]
class SetupInstructionsController extends AppController
{
    public function __invoke(Request $request): Response
    {
        // The value lands in shell commands an agent runs, so only a UUID passes.
        $projectId = $request->query->all()['project'] ?? null;
        if (!\is_string($projectId) || !Uuid::isValid($projectId)) {
            $projectId = null;
        }

        return new Response(
            $this->renderView('@Project/setup_instructions.md.twig', [
                'projectId' => null === $projectId ? null : Uuid::fromString($projectId)->toRfc4122(),
            ]),
            Response::HTTP_OK,
            ['Content-Type' => 'text/markdown; charset=utf-8'],
        );
    }
}
