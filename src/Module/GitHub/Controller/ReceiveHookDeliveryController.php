<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Controller\AppController;
use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use App\Module\GitHub\Command\GitHubDeliveryOutcome;
use App\Module\GitHub\Command\ReceiveHookDeliveryCommand;
use App\Module\GitHub\Command\ReceiveHookDeliveryHandler;
use App\Module\GitHub\Entity\GitHubHook;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives what a repository hook says. An unknown key answers 404 before the
 * body is read. The key is not aliased, because the rate limiter reads the
 * `hookKey` attribute.
 */
#[Route(
    '/webhooks/forge/github/{hookKey}',
    name: 'webhook_forge_github_hook',
    requirements: ['hookKey' => GitHubHook::KEY_PATTERN],
    defaults: [
        RateLimitForgeDeliveries::MARKER => true,
        RateLimitForgeDeliveries::KEYING => RateLimitForgeDeliveries::KEY_BY_HOOK_KEY,
    ],
    methods: ['POST'],
)]
final class ReceiveHookDeliveryController extends AppController
{
    public function __construct(
        private readonly ReceiveHookDeliveryHandler $receiveDelivery,
        private readonly AuditContext $auditContext,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(mapping: ['hookKey' => 'hookKey'])] GitHubHook $hook,
    ): Response {
        $this->auditContext->channel = AuditChannel::Webhook;

        return match (($this->receiveDelivery)(new ReceiveHookDeliveryCommand($hook, $request))) {
            GitHubDeliveryOutcome::Refused => new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST),
            GitHubDeliveryOutcome::Received => new JsonResponse(['received' => true]),
        };
    }
}
