<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\SeedInboxFixtureCommand;
use App\Module\Inbox\Command\SeedInboxFixtureHandler;
use App\Module\Project\Command\EnsureHarnessProjectCommand;
use App\Module\Project\Command\EnsureHarnessProjectHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Dev-only: seeds an open ask with a question and a to-do in the signed-in user's harness project. */
#[Route(
    '/dev/seed/inbox',
    name: 'app_dev_seed_inbox',
    methods: ['POST'],
)]
#[When('dev')]
final class SeedInboxController extends AppController
{
    public function __construct(
        private readonly EnsureHarnessProjectHandler $ensureHarnessProject,
        private readonly SeedInboxFixtureHandler $seedInbox,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User; this route must stay behind the ROLE_USER catch-all.', self::class));
        }

        $project = ($this->ensureHarnessProject)(new EnsureHarnessProjectCommand($user, 'e2e-harness'));
        $seeded = ($this->seedInbox)(new SeedInboxFixtureCommand($project));

        return $this->json([
            'projectId' => (string) $project->id,
            'askId' => (string) $seeded['ask']->id,
            'questionNumber' => $seeded['question']->number,
            'todoNumber' => $seeded['todo']->number,
        ], JsonResponse::HTTP_CREATED);
    }
}
