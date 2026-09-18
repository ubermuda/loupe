<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Dev;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\AskInboxCommand;
use App\Module\Inbox\Command\AskInboxHandler;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Command\EnsureHarnessProjectCommand;
use App\Module\Project\Command\EnsureHarnessProjectHandler;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/dev/seed/inbox-review',
    name: 'app_dev_seed_inbox_review',
    methods: ['POST'],
)]
#[When('dev')]
final class SeedInboxReviewController extends AppController
{
    public function __construct(
        private readonly EnsureHarnessProjectHandler $ensureHarnessProject,
        private readonly AskInboxHandler $askInbox,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The review fixture requires an authenticated user.');
        }
        $project = ($this->ensureHarnessProject)(new EnsureHarnessProjectCommand($user, 'e2e-harness'));
        $cardId = $request->request->getString('cardId');
        $view = ($this->askInbox)(new AskInboxCommand($project, Uuid::v4(), [new AskInboxItem(
            kind: InboxItemKind::Review,
            title: 'Review the linked work',
            blocking: true,
            cardIds: '' === $cardId ? [] : [$cardId],
            reviewDocumentId: $request->request->getString('documentId') ?: null,
            reviewPullRequestId: $request->request->getString('pullRequestId') ?: null,
        )]));

        return $this->json(['projectId' => (string) $project->id, 'itemId' => (string) $view->items[0]->id, 'number' => $view->items[0]->number], JsonResponse::HTTP_CREATED);
    }
}
