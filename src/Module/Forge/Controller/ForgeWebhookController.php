<?php

declare(strict_types=1);

namespace App\Module\Forge\Controller;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Controller\AppController;
use App\Module\Forge\Command\ForgeDeliveryOutcome;
use App\Module\Forge\Command\ReceiveForgeDeliveryCommand;
use App\Module\Forge\Command\ReceiveForgeDeliveryHandler;
use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives what a forge says about a pull request.
 *
 * Deliberately not under /api: the api firewall lets any scoped token through
 * on unlisted /api paths, and a forge authenticates with a signature rather
 * than a bearer token.
 *
 * Every recognised outcome answers 200: a forge retries anything else for days,
 * and a delivery about a repository this app does not know is not an error to
 * fix.
 */
#[Route(
    '/webhooks/forge/{forge}',
    name: 'webhook_forge',
    requirements: ['forge' => '[a-z]+'],
    defaults: [
        RateLimitForgeDeliveries::MARKER => true,
        RateLimitForgeDeliveries::KEYING => RateLimitForgeDeliveries::KEY_BY_ADDRESS,
    ],
    methods: ['POST'],
)]
final class ForgeWebhookController extends AppController
{
    public function __construct(
        private readonly ReceiveForgeDeliveryHandler $receiveDelivery,
        private readonly AuditContext $auditContext,
    ) {
    }

    public function __invoke(Request $request, string $forge): Response
    {
        // Declared, never detected: an anonymous request is not evidence of a
        // webhook, because registration and password reset are anonymous too.
        $this->auditContext->channel = AuditChannel::Webhook;

        $outcome = ($this->receiveDelivery)(new ReceiveForgeDeliveryCommand($forge, $request));

        return match ($outcome) {
            ForgeDeliveryOutcome::UnknownForge => new JsonResponse(['error' => 'unknown forge'], Response::HTTP_NOT_FOUND),
            ForgeDeliveryOutcome::InvalidSignature => new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST),
            ForgeDeliveryOutcome::Received => new JsonResponse(['received' => true]),
        };
    }
}
