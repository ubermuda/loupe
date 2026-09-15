<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\CheckInboxAskCommand;
use App\Module\Inbox\Command\CheckInboxAskHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Security\ApiTokenRateLimitKey;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Attribute\RequireFeatureFlag;

/**
 * Tells the bridge, before it resumes a session, whether the ask closed and
 * whether that session already read every item. The firewall admits
 * agent-scoped tokens alone.
 *
 * The rate limit key expression sees only the request, the arguments and this
 * controller, so the key service rides on a public property.
 */
#[RateLimit('agent_inbox_ask_checks', key: new Expression('this.rateLimitKey.forRequest(request)'))]
#[RequireFeatureFlag(InboxInstallFlags::FLAG_INBOX_ENABLED)]
#[Route(
    '/api/projects/{handle}/inbox/asks/{askId}',
    name: 'api_project_inbox_ask_check',
    requirements: ['handle' => '[^/]+', 'askId' => Requirement::UUID],
    methods: ['GET'],
)]
final class CheckInboxAskController extends AppController
{
    public function __construct(
        private readonly CheckInboxAskHandler $checkAsk,
        public readonly ApiTokenRateLimitKey $rateLimitKey,
    ) {
    }

    public function __invoke(string $handle, string $askId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Inbox ask check reached without an authenticated User.');
        }

        $view = ($this->checkAsk)(new CheckInboxAskCommand($user, $handle, Uuid::fromString($askId)));

        if (null === $view->project) {
            return $this->json(['error' => 'project_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }
        if (null === $view->ask) {
            return $this->json(['error' => 'ask_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return $this->json([
            'askId' => (string) $view->ask->id,
            'closed' => null !== $view->ask->closedAt,
            'allRead' => $view->allRead,
        ]);
    }
}
