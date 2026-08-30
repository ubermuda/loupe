<?php

declare(strict_types=1);

namespace App\Module\Billing\Controller;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Controller\AppController;
use App\Module\Billing\Command\SyncStripeSubscriptionCommand;
use App\Module\Billing\Command\SyncStripeSubscriptionHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Service\StripeSubscriptionPeriodEnd;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deliberately not under /api: the api firewall lets any scoped token through on
 * unlisted /api paths, and Stripe authenticates with a signature rather than a
 * bearer token. Verifying that signature needs the exact bytes Stripe sent, which
 * is why this controller reads the raw request instead of #[MapRequestPayload].
 *
 * Every recognised outcome answers 200: Stripe retries anything else for days,
 * and an event about a customer this app does not know is not an error to fix.
 */
#[Route(
    '/webhooks/stripe',
    name: 'webhook_stripe',
    methods: ['POST'],
)]
final class StripeWebhookController extends AppController
{
    private const array HANDLED_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(
        private readonly SyncStripeSubscriptionHandler $syncSubscription,
        private readonly LoggerInterface $logger,
        private readonly AuditContext $auditContext,

        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        // Declared, never detected: an anonymous request is not evidence of a
        // webhook — registration and password reset are anonymous too.
        $this->auditContext->channel = AuditChannel::Webhook;

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->headers->get('Stripe-Signature', ''),
                $this->webhookSecret,
            );
        } catch (SignatureVerificationException|StripeUnexpectedValueException $e) {
            $this->logger->warning('billing.webhook.rejected', ['error' => $e->getMessage()]);

            return new JsonResponse(['error' => 'invalid signature'], Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($event->type, self::HANDLED_EVENTS, true)) {
            return new JsonResponse(['received' => true]);
        }

        $subscription = $event->data->object;
        $eventType = is_string($event->type) ? $event->type : '';
        $eventId = is_string($event->id) ? $event->id : '';
        $customerId = is_string($subscription['customer'] ?? null) ? $subscription['customer'] : '';
        $subscriptionId = is_string($subscription['id'] ?? null) ? $subscription['id'] : '';
        if ('' === $eventType || '' === $eventId || '' === $customerId || '' === $subscriptionId) {
            $this->logger->warning('billing.webhook.malformed', ['eventType' => $event->type]);

            return new JsonResponse(['received' => true]);
        }

        ($this->syncSubscription)(new SyncStripeSubscriptionCommand(
            stripeEventId: $eventId,
            stripeCustomerId: $customerId,
            stripeSubscriptionId: $subscriptionId,
            stripeStatus: BillingProfile::SUBSCRIPTION_DELETED_EVENT_TYPE === $eventType
                ? 'canceled'
                : (is_string($subscription['status'] ?? null) ? $subscription['status'] : 'canceled'),
            stripeEventType: $eventType,
            currentPeriodEnd: StripeSubscriptionPeriodEnd::from($subscription),
            eventCreatedAt: new \DateTimeImmutable('@'.$event->created),
        ));

        return new JsonResponse(['received' => true]);
    }
}
