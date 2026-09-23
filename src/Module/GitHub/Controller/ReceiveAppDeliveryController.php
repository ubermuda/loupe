<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Controller\AppController;
use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use App\Module\GitHub\Command\GitHubDeliveryOutcome;
use App\Module\GitHub\Command\ReceiveAppDeliveryCommand;
use App\Module\GitHub\Command\ReceiveAppDeliveryHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives what the GitHub App says. Not under /api: the api firewall lets any
 * scoped token through on unlisted /api paths, and GitHub authenticates with a
 * signature rather than a bearer token. Every recognised outcome answers 200,
 * because GitHub retries anything else for days.
 */
#[Route(
    '/webhooks/forge/github',
    name: 'webhook_forge_github',
    defaults: [
        RateLimitForgeDeliveries::MARKER => true,
        RateLimitForgeDeliveries::KEYING => RateLimitForgeDeliveries::KEY_BY_ADDRESS,
    ],
    methods: ['POST'],
)]
final class ReceiveAppDeliveryController extends AppController
{
    public function __construct(
        private readonly ReceiveAppDeliveryHandler $receiveDelivery,
        private readonly AuditContext $auditContext,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Declared, never detected: an anonymous request is not evidence of a
        // webhook, because registration and password reset are anonymous too.
        $this->auditContext->channel = AuditChannel::Webhook;

        return match (($this->receiveDelivery)(new ReceiveAppDeliveryCommand($request))) {
            GitHubDeliveryOutcome::Refused => new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST),
            GitHubDeliveryOutcome::Received => new JsonResponse(['received' => true]),
        };
    }
}
