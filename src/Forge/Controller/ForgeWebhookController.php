<?php

declare(strict_types=1);

namespace App\Forge\Controller;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Controller\AppController;
use App\Forge\Event\ForgeDeliveryReceived;
use App\Forge\ForgeAdapters;
use App\Forge\InvalidForgeSignature;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

/**
 * Receives what a forge says about a pull request, and turns it into Loupe's
 * own events.
 *
 * Deliberately not under /api: the api firewall lets any scoped token through
 * on unlisted /api paths, and a forge authenticates with a signature rather
 * than a bearer token. Verifying that signature needs the exact bytes the forge
 * sent, which is why the adapter reads the raw request instead of
 * #[MapRequestPayload].
 *
 * Every recognised outcome answers 200: a forge retries anything else for days,
 * and a delivery about a repository this app does not know is not an error to
 * fix.
 */
#[Route(
    '/webhooks/forge/{forge}',
    name: 'webhook_forge',
    requirements: ['forge' => '[a-z]+'],
    methods: ['POST'],
)]
final class ForgeWebhookController extends AppController
{
    public function __construct(
        private readonly ForgeAdapters $adapters,
        private readonly EventDispatcherInterface $events,
        private readonly LoggerInterface $logger,
        private readonly AuditContext $auditContext,
        private readonly Auditor $auditor,
    ) {
    }

    public function __invoke(Request $request, string $forge): Response
    {
        // Declared, never detected: an anonymous request is not evidence of a
        // webhook, because registration and password reset are anonymous too.
        $this->auditContext->channel = AuditChannel::Webhook;

        $adapter = $this->adapters->forSlug($forge);
        if (null === $adapter) {
            return new JsonResponse(['error' => 'unknown forge'], Response::HTTP_NOT_FOUND);
        }

        try {
            $deliveries = $adapter->translate($request);
        } catch (InvalidForgeSignature $e) {
            // No subject and no context: the signature failed, so nothing in
            // the request is trustworthy, including the repository it names.
            $this->auditor->record(
                'board.forge_delivery_rejected',
                AuditOutcome::Refused,
                category: Auditor::CATEGORY_SECURITY,
            );

            $this->logger->warning('board.forge_delivery_rejected', [
                'forge' => $forge,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        if ([] !== $deliveries) {
            $this->events->dispatch(new ForgeDeliveryReceived($deliveries));
        }

        return new JsonResponse(['received' => true]);
    }
}
