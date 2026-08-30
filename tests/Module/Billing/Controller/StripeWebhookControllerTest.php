<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller;

use App\Audit\AuditChannel;
use App\Audit\AuditContext;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\AuditEvent;
use App\Module\Audit\Auditor;
use App\Module\Billing\Entity\BillingStatus;
use App\Tests\Support\BillingScenario;
use App\Tests\Support\FakeAuditSink;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payloads are signed exactly the way Stripe signs them, so the real
 * Webhook::constructEvent verification runs.
 */
final class StripeWebhookControllerTest extends WebTestCase
{
    private const string WEBHOOK_SECRET = 'whsec_test';

    private const string CUSTOMER_ID = 'cus_webhook';

    private function sign(string $payload): string
    {
        $timestamp = time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET));
    }

    /** @param array<string, mixed> $subscription */
    private function payload(string $type, array $subscription, int $created, string $eventId = 'evt_1'): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'created' => $created,
            'data' => ['object' => $subscription],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function classicSubscription(string $status = 'active'): array
    {
        return [
            'id' => 'sub_webhook',
            'object' => 'subscription',
            'customer' => self::CUSTOMER_ID,
            'status' => $status,
            'current_period_end' => 1893456000,
        ];
    }

    private function post(KernelBrowser $client, string $payload, ?string $signature = null): void
    {
        $client->request(
            Request::METHOD_POST,
            '/webhooks/stripe',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature ?? $this->sign($payload)],
            content: $payload,
        );
    }

    private function seedProfile(string $customerId = self::CUSTOMER_ID): void
    {
        $scenario = new BillingScenario(static::getContainer());
        $user = $scenario->verifiedUser('hooked'.substr(md5($customerId), 0, 8));
        $profile = $scenario->profile($user, new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = $customerId;
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** @return array<string, mixed>|false */
    private function storedProfile(): array|false
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT s.stripe_status AS status, s.stripe_subscription_id, s.ends_at, p.last_stripe_event_at
                FROM billing_profiles p
                LEFT JOIN subscriptions s ON s.billing_profile_id = p.id AND s.kind = 'stripe'
                WHERE p.stripe_customer_id = ?
                SQL,
            [self::CUSTOMER_ID],
        );
    }

    public function test_an_unauthenticated_signed_event_reaches_the_controller(): void
    {
        $client = static::createClient();
        $this->seedProfile();

        $this->post($client, $this->payload('customer.subscription.updated', $this->classicSubscription(), time()));

        self::assertResponseIsSuccessful();
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        self::assertSame(BillingStatus::Active->value, $stored['status']);
        self::assertSame('sub_webhook', $stored['stripe_subscription_id']);
        self::assertNotNull($stored['ends_at']);
    }

    /**
     * Audits from inside the handler's own flush rather than reading the
     * channel back afterwards: a declaration made too late would still leave
     * `webhook` behind at the end of the request while every write it was
     * meant to label had already been recorded as something else.
     */
    private function auditingEachWrite(): FakeAuditSink
    {
        $sink = new FakeAuditSink();
        $provider = static::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $provider);
        $auditor = new Auditor([$sink], $provider, new NullLogger(), new MockClock());

        static::getContainer()->get(EntityManagerInterface::class)->getEventManager()->addEventListener(
            [Events::onFlush],
            new readonly class($auditor) {
                public function __construct(
                    private Auditor $auditor,
                ) {
                }

                public function onFlush(): void
                {
                    $this->auditor->record('billing.subscription.synced');
                }
            },
        );

        return $sink;
    }

    /**
     * @param list<AuditEvent> $events
     *
     * @return list<string>
     */
    private function channelsIn(array $events): array
    {
        return array_values(array_unique(array_map(static fn (AuditEvent $event): string => $event->channel, $events)));
    }

    public function test_a_write_from_the_webhook_is_recorded_on_the_webhook_channel(): void
    {
        $client = static::createClient();
        $this->seedProfile();
        $sink = $this->auditingEachWrite();

        $this->post($client, $this->payload('customer.subscription.updated', $this->classicSubscription(), time()));

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($sink->events);
        self::assertSame([AuditChannel::Webhook->value], $this->channelsIn($sink->events));
    }

    /**
     * The replay branch returns before the profile is touched, so it is where a
     * channel declared late enough to miss it would first show.
     */
    public function test_an_early_returning_branch_is_also_on_the_webhook_channel(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->seedProfile();
        $sink = $this->auditingEachWrite();

        $payload = $this->payload('customer.subscription.updated', $this->classicSubscription(), time());
        $this->post($client, $payload);
        $beforeReplay = count($sink->events);
        $this->post($client, $payload);

        self::assertResponseIsSuccessful();
        $replayed = array_slice($sink->events, $beforeReplay);
        self::assertNotEmpty($replayed);
        self::assertSame([AuditChannel::Webhook->value], $this->channelsIn($replayed));
    }

    /**
     * Kernel::boot() resets services before the next request, which is what
     * AuditContext implements ResetInterface for. Mostly theoretical under
     * php-fpm, where each request is its own process — not theoretical the day
     * this runs in a long-lived worker.
     */
    public function test_the_declared_channel_does_not_survive_into_the_next_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->seedProfile();

        $auditContext = static::getContainer()->get(AuditContext::class);
        self::assertInstanceOf(AuditContext::class, $auditContext);
        $provider = static::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $provider);

        $this->post($client, $this->payload('customer.subscription.updated', $this->classicSubscription(), time()));
        self::assertSame(AuditChannel::Webhook, $auditContext->channel);

        $client->request(Request::METHOD_GET, '/login');

        self::assertNull($auditContext->channel);
        self::assertSame(AuditChannel::System->value, $provider->currentActor()->channel);
    }

    public function test_a_tampered_signature_is_rejected_and_writes_nothing(): void
    {
        $client = static::createClient();
        $this->seedProfile();

        $this->post($client, $this->payload('customer.subscription.updated', $this->classicSubscription(), time()), 't=1,v1=deadbeef');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        // No Stripe grant was ever written, so the left join yields nothing.
        self::assertNull($stored['status']);
        self::assertNull($stored['stripe_subscription_id']);
    }

    public function test_a_missing_signature_header_is_rejected(): void
    {
        $client = static::createClient();

        $client->request(
            Request::METHOD_POST,
            '/webhooks/stripe',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $this->payload('customer.subscription.updated', $this->classicSubscription(), time()),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function test_a_malformed_body_is_rejected(): void
    {
        $client = static::createClient();

        $this->post($client, 'not json at all');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function test_the_basil_payload_shape_is_understood(): void
    {
        $client = static::createClient();
        $this->seedProfile();

        $subscription = $this->classicSubscription();
        unset($subscription['current_period_end']);
        $subscription['items'] = ['object' => 'list', 'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'current_period_end' => 1893456000]]];

        $this->post($client, $this->payload('customer.subscription.updated', $subscription, time()));

        self::assertResponseIsSuccessful();
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        self::assertNotNull($stored['ends_at']);
    }

    public function test_a_payload_with_no_period_end_at_all_is_accepted(): void
    {
        $client = static::createClient();
        $this->seedProfile();

        $subscription = $this->classicSubscription();
        unset($subscription['current_period_end']);

        $this->post($client, $this->payload('customer.subscription.updated', $subscription, time()));

        self::assertResponseIsSuccessful();
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        self::assertNull($stored['ends_at']);
    }

    public function test_a_deletion_cancels_the_subscription(): void
    {
        $client = static::createClient();
        $this->seedProfile();

        $this->post($client, $this->payload('customer.subscription.deleted', $this->classicSubscription('active'), time()));

        self::assertResponseIsSuccessful();
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        self::assertSame(BillingStatus::Canceled->value, $stored['status']);
    }

    public function test_a_replayed_delivery_of_the_same_event_does_not_reapply_it(): void
    {
        $client = static::createClient();
        $this->seedProfile();
        $created = time();

        $this->post($client, $this->payload('customer.subscription.updated', $this->classicSubscription('active'), $created, 'evt_replay'));
        // Same event id: a Stripe retry, not new information. The differing
        // status proves the second delivery was ignored rather than applied.
        $this->post($client, $this->payload('customer.subscription.deleted', $this->classicSubscription('canceled'), $created, 'evt_replay'));

        self::assertResponseIsSuccessful();
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        self::assertSame(BillingStatus::Active->value, $stored['status']);
    }

    public function test_two_distinct_events_in_the_same_second_both_apply(): void
    {
        $client = static::createClient();
        $this->seedProfile();
        $created = time();

        $this->post($client, $this->payload('customer.subscription.updated', $this->classicSubscription('incomplete'), $created, 'evt_created'));
        $this->post($client, $this->payload('customer.subscription.updated', $this->classicSubscription('active'), $created, 'evt_updated'));

        self::assertResponseIsSuccessful();
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        self::assertSame(BillingStatus::Active->value, $stored['status']);
    }

    public function test_an_unknown_customer_is_acknowledged(): void
    {
        $client = static::createClient();

        $subscription = $this->classicSubscription();
        $subscription['customer'] = 'cus_someone_else';

        $this->post($client, $this->payload('customer.subscription.updated', $subscription, time()));

        self::assertResponseIsSuccessful();
    }

    public function test_an_unhandled_event_type_is_acknowledged_without_writing(): void
    {
        $client = static::createClient();
        $this->seedProfile();

        $this->post($client, $this->payload('invoice.paid', $this->classicSubscription(), time()));

        self::assertResponseIsSuccessful();
        $stored = $this->storedProfile();
        self::assertIsArray($stored);
        self::assertNull($stored['stripe_subscription_id']);
    }
}
