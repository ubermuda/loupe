<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Command;

use App\Audit\AuditChannel;
use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\NullAuditActorProvider;
use App\Module\Billing\Command\SyncStripeSubscriptionCommand;
use App\Module\Billing\Command\SyncStripeSubscriptionHandler;
use App\Module\Billing\Entity\BillingProfile;
use App\Module\Billing\Entity\BillingStatus;
use App\Module\Billing\Entity\Subscription;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Repository\SubscriptionRepository;
use App\Module\Billing\Service\StripeGatewayInterface;
use App\Module\Billing\Service\SubscriptionView;
use App\Tests\Support\BillingGrants;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TransactionalEntityManagerStub;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMInvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class SyncStripeSubscriptionHandlerTest extends TestCase
{
    private RecordingAuditor $audit;

    #[\Override]
    protected function setUp(): void
    {
        $this->audit = new RecordingAuditor(new NullAuditActorProvider());
    }

    private function profile(): BillingProfile
    {
        $user = new User(fullName: 'Synced User', email: 'synced@example.com', password: 'irrelevant');
        new \ReflectionProperty(User::class, 'id')->setValue($user, Uuid::v4());
        $profile = BillingGrants::profileWithTrial($user, new \DateTimeImmutable('-1 day'));
        $profile->stripeCustomerId = 'cus_123';

        return $profile;
    }

    /** The grant the webhook writes. The handler creates it on the first event. */
    private function grant(BillingProfile $profile): Subscription
    {
        return $profile->latestSubscriptionOfKind(SubscriptionKind::Stripe)
            ?? throw new \LogicException('the handler should have created a Stripe grant');
    }

    /**
     * The repository resolves out of the in-memory profile, so a second event
     * finds the grant the first one created rather than starting another.
     */
    private function subscriptionRepository(?BillingProfile $profile): SubscriptionRepository
    {
        $subscriptions = $this->createStub(SubscriptionRepository::class);
        $subscriptions->method('findOneByStripeSubscriptionId')->willReturnCallback(
            static fn (string $id): ?Subscription => null === $profile ? null : array_find(
                $profile->subscriptions->toArray(),
                static fn (Subscription $subscription): bool => $id === $subscription->stripeSubscriptionId,
            ),
        );

        return $subscriptions;
    }

    private function handler(
        ?BillingProfile $profile,
        ?WaitlistEntry $waitlistEntry = null,
        ?LoggerInterface $logger = null,
        ?SubscriptionView $stripeState = null,
        ?EntityManagerInterface $em = null,
    ): SyncStripeSubscriptionHandler {
        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByStripeCustomerId')->willReturn($profile);

        // Keyed on the address so a lookup with anything but the profile
        // user's email comes back empty and the conversion tests fail.
        $waitlistEntries = $this->createStub(WaitlistEntryRepository::class);
        $waitlistEntries->method('findOneByEmail')->willReturnCallback(
            static fn (string $email): ?WaitlistEntry => 'synced@example.com' === $email ? $waitlistEntry : null,
        );

        $stripe = $this->createStub(StripeGatewayInterface::class);
        $stripe->method('retrieveSubscription')->willReturn($stripeState);

        return new SyncStripeSubscriptionHandler(
            $profiles,
            $this->subscriptionRepository($profile),
            $stripe,
            $waitlistEntries,
            $em ?? TransactionalEntityManagerStub::configure($this->createStub(EntityManagerInterface::class)),
            $logger ?? new NullLogger(),
            $this->audit->auditor,
        );
    }

    /**
     * @param non-empty-string $eventId
     * @param non-empty-string $eventType
     */
    private function command(
        string $status,
        string $eventCreatedAt = 'now',
        string $eventId = 'evt_1',
        string $eventType = 'customer.subscription.updated',
        ?string $currentPeriodEnd = '+30 days',
    ): SyncStripeSubscriptionCommand {
        return new SyncStripeSubscriptionCommand(
            stripeEventId: $eventId,
            stripeCustomerId: 'cus_123',
            stripeSubscriptionId: 'sub_123',
            stripeStatus: $status,
            stripeEventType: $eventType,
            currentPeriodEnd: null === $currentPeriodEnd ? null : new \DateTimeImmutable($currentPeriodEnd),
            eventCreatedAt: new \DateTimeImmutable($eventCreatedAt),
        );
    }

    /** @return iterable<string, array{string, BillingStatus}> */
    public static function statuses(): iterable
    {
        yield 'active' => ['active', BillingStatus::Active];
        yield 'past_due' => ['past_due', BillingStatus::PastDue];
        yield 'canceled' => ['canceled', BillingStatus::Canceled];
    }

    #[DataProvider('statuses')]
    public function test_subscription_state_is_written_onto_a_stripe_grant(string $stripeStatus, BillingStatus $expected): void
    {
        $profile = $this->profile();

        ($this->handler($profile))($this->command($stripeStatus));

        $grant = $this->grant($profile);
        self::assertSame($expected, $grant->stripeStatus);
        self::assertSame('sub_123', $grant->stripeSubscriptionId);
        self::assertNotNull($grant->endsAt);
        self::assertNotNull($profile->lastStripeEventAt);
    }

    public function test_a_second_event_updates_the_grant_rather_than_adding_one(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_first'));
        $handler($this->command('past_due', '2026-07-25 12:00:06', 'evt_second'));

        self::assertCount(2, $profile->subscriptions);
        self::assertSame(BillingStatus::PastDue, $this->grant($profile)->stripeStatus);
    }

    /**
     * A comp is not stored where Stripe writes, so no guard is needed to keep
     * a webhook off it.
     */
    public function test_a_webhook_leaves_a_comp_untouched(): void
    {
        $profile = $this->profile();
        $comp = BillingGrants::comp($profile);

        ($this->handler($profile))($this->command('canceled', eventType: 'customer.subscription.deleted', currentPeriodEnd: '-1 hour'));

        self::assertSame(SubscriptionKind::Comp, $comp->kind);
        self::assertNull($comp->endsAt);
        self::assertNull($comp->stripeStatus);
        self::assertTrue($comp->isCurrent(new \DateTimeImmutable()));
        // The comp still grants access, so the cancellation disables nothing.
        self::assertNull($profile->user->disabledAt);
    }

    /**
     * A profile may hold only one current Stripe grant, so a webhook about a
     * second one cannot be applied. It must not raise, because a 500 makes
     * Stripe redeliver an event that fails the same way every time.
     */
    public function test_a_second_concurrent_stripe_grant_is_logged_and_not_created(): void
    {
        $profile = $this->profile();
        $endsAt = new \DateTimeImmutable('+30 days');
        $existing = BillingGrants::stripe($profile, BillingStatus::Active, $endsAt, 'sub_other');
        $logger = new RecordingLogger();

        ($this->handler($profile, logger: $logger))($this->command('canceled', eventType: 'customer.subscription.deleted'));

        self::assertCount(2, $profile->subscriptions);
        self::assertSame(BillingStatus::Active, $existing->stripeStatus);
        self::assertSame($endsAt, $existing->endsAt);

        $refused = array_values(array_filter($logger->records, static fn (array $record): bool => 'billing.webhook.concurrent_grant' === $record['message']));
        self::assertCount(1, $refused);
        self::assertSame('error', $refused[0]['level']);
        self::assertSame('cus_123', $refused[0]['context']['stripeCustomerId']);
        self::assertSame('sub_123', $refused[0]['context']['stripeSubscriptionId']);
        self::assertSame('evt_1', $refused[0]['context']['eventId']);

        // The event never took effect, so the ordering bookkeeping must not
        // claim it did.
        self::assertNull($profile->lastStripeEventId);
        self::assertNull($profile->lastStripeEventAt);
        self::assertNull($profile->lastStripeEventType);
    }

    /**
     * ORMInvalidArgumentException is a \LogicException, so a catch around the
     * construction path would file a Doctrine fault as a duplicate grant.
     */
    public function test_a_doctrine_fault_while_creating_a_grant_is_not_reported_as_a_duplicate(): void
    {
        $profile = $this->profile();
        $logger = new RecordingLogger();
        $fault = ORMInvalidArgumentException::scheduleInsertTwice($profile);

        /** @var Collection<int, Subscription>&Stub $subscriptions */
        $subscriptions = $this->createStub(Collection::class);
        $subscriptions->method('toArray')->willReturn($profile->subscriptions->toArray());
        $subscriptions->method('add')->willThrowException($fault);
        $profile->subscriptions = $subscriptions;

        try {
            ($this->handler($profile, logger: $logger))($this->command('active'));
            self::fail('the Doctrine fault must propagate out of the handler');
        } catch (ORMInvalidArgumentException $caught) {
            self::assertSame($fault, $caught);
        }

        self::assertSame([], array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'billing.webhook.concurrent_grant' === $record['message'],
        )));
    }

    public function test_an_unknown_customer_is_ignored_without_throwing(): void
    {
        ($this->handler(null))($this->command('active'));

        $this->expectNotToPerformAssertions();
    }

    public function test_an_out_of_order_event_does_not_resurrect_a_cancelled_subscription(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('canceled', '2026-07-25 12:00:05', 'evt_deleted', 'customer.subscription.deleted'));
        $handler($this->command('active', '2026-07-25 12:00:01', 'evt_older_update'));

        self::assertSame(BillingStatus::Canceled, $this->grant($profile)->stripeStatus);
    }

    public function test_a_replayed_event_is_a_no_op(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_same'));
        $handler($this->command('canceled', '2026-07-25 12:00:05', 'evt_same'));

        self::assertSame(BillingStatus::Active, $this->grant($profile)->stripeStatus);
    }

    /**
     * Stripe's `created` has one-second resolution, so a `created` event and the
     * `updated` event right behind it legitimately share a timestamp. Only the
     * id can tell them apart, and both must land.
     */
    public function test_a_distinct_event_in_the_same_second_is_applied(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('incomplete', '2026-07-25 12:00:05', 'evt_created'));
        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_updated'));

        self::assertSame(BillingStatus::Active, $this->grant($profile)->stripeStatus);
    }

    /**
     * The dangerous same-second case: a cancellation and an older `updated`
     * share a second and Stripe delivers them out of order. Access must not come
     * back.
     */
    public function test_a_same_second_update_never_undoes_a_cancellation(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('canceled', '2026-07-25 12:00:05', 'evt_deleted', 'customer.subscription.deleted'));
        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_stale_update'));

        self::assertSame(BillingStatus::Canceled, $this->grant($profile)->stripeStatus);
    }

    /**
     * The reverse of the case above: a `created` (incomplete) delivered after
     * its own `updated` (active) from the same second. Arrival order alone would
     * paywall someone who has just paid, so Stripe's current state decides.
     */
    public function test_a_same_second_pair_delivered_out_of_order_is_settled_by_stripe(): void
    {
        $profile = $this->profile();
        $handler = $this->handler(
            $profile,
            stripeState: new SubscriptionView('active', new \DateTimeImmutable('2026-08-25 12:00:00')),
        );

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_updated'));
        $handler($this->command('incomplete', '2026-07-25 12:00:05', 'evt_created'));

        $grant = $this->grant($profile);
        self::assertSame(BillingStatus::Active, $grant->stripeStatus);
        self::assertEquals(new \DateTimeImmutable('2026-08-25 12:00:00'), $grant->endsAt);
    }

    /** An unreachable Stripe leaves the previous arrival-order behaviour intact. */
    public function test_an_unanswered_lookup_falls_back_to_arrival_order(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile, stripeState: null);

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_updated'));
        $handler($this->command('incomplete', '2026-07-25 12:00:05', 'evt_created'));

        self::assertSame(BillingStatus::Canceled, $this->grant($profile)->stripeStatus);
    }

    /** A lone event is not a tie, so it costs no Stripe call. */
    public function test_a_first_event_is_not_looked_up(): void
    {
        $profile = $this->profile();
        $stripe = $this->createMock(StripeGatewayInterface::class);
        $stripe->expects($this->never())->method('retrieveSubscription');

        $profiles = $this->createStub(BillingProfileRepository::class);
        $profiles->method('findOneByStripeCustomerId')->willReturn($profile);

        $handler = new SyncStripeSubscriptionHandler(
            $profiles,
            $this->subscriptionRepository($profile),
            $stripe,
            $this->createStub(WaitlistEntryRepository::class),
            TransactionalEntityManagerStub::configure($this->createStub(EntityManagerInterface::class)),
            new NullLogger(),
            $this->audit->auditor,
        );

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_only'));

        self::assertSame(BillingStatus::Active, $this->grant($profile)->stripeStatus);
    }

    public function test_a_newer_event_is_applied(): void
    {
        $profile = $this->profile();
        $handler = $this->handler($profile);

        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_first'));
        $handler($this->command('canceled', '2026-07-25 12:00:06', 'evt_second'));

        self::assertSame(BillingStatus::Canceled, $this->grant($profile)->stripeStatus);
    }

    public function test_activation_of_a_disabled_account_reenables_it_and_resets_the_survey_marker(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');
        $grant = BillingGrants::stripe($profile, BillingStatus::Canceled, new \DateTimeImmutable('-1 day'), 'sub_123');
        $grant->surveySentAt = new \DateTimeImmutable('-1 day');

        ($this->handler($profile))($this->command('active'));

        self::assertNull($profile->user->disabledAt);
        self::assertNull($grant->surveySentAt);

        $record = $this->audit->record('billing.account.reenabled');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $profile->user->id, $record->subject->id);
        self::assertSame(['userId' => (string) $profile->user->id], $record->context);
        self::assertSame(['billing.account.reenabled'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_activation_of_a_disabled_account_converts_a_matching_waitlist_entry(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');
        $entry = new WaitlistEntry('synced@example.com');

        ($this->handler($profile, $entry))($this->command('active'));

        self::assertNotNull($entry->convertedAt);
    }

    public function test_activation_does_not_reconvert_an_already_converted_waitlist_entry(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');
        $entry = new WaitlistEntry('synced@example.com');
        $convertedAt = new \DateTimeImmutable('2026-01-01 09:00:00');
        $entry->convertedAt = $convertedAt;

        ($this->handler($profile, $entry))($this->command('active'));

        self::assertSame($convertedAt, $entry->convertedAt);
    }

    /** @return iterable<string, array{?string}> */
    public static function lapsedPeriodEnds(): iterable
    {
        yield 'past' => ['-1 hour'];
        yield 'null' => [null];
    }

    #[DataProvider('lapsedPeriodEnds')]
    public function test_a_cancellation_whose_paid_period_is_over_disables_the_account(?string $currentPeriodEnd): void
    {
        $profile = $this->profile();

        ($this->handler($profile))(
            $this->command('canceled', eventType: 'customer.subscription.deleted', currentPeriodEnd: $currentPeriodEnd),
        );

        self::assertNotNull($profile->user->disabledAt);

        $record = $this->audit->record('billing.account.disabled_on_cancel');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $profile->user->id, $record->subject->id);
        self::assertSame(['userId' => (string) $profile->user->id], $record->context);
    }

    /**
     * The re-enable block must be gated on the account actually being
     * disabled — an ordinary update event for an active account must not
     * wipe the survey marker.
     */
    public function test_activation_of_an_enabled_account_leaves_the_survey_marker_alone(): void
    {
        $profile = $this->profile();
        $marker = new \DateTimeImmutable('-1 day');
        $grant = BillingGrants::stripe($profile, BillingStatus::Active, new \DateTimeImmutable('+30 days'), 'sub_123');
        $grant->surveySentAt = $marker;

        ($this->handler($profile))($this->command('active'));

        self::assertSame($marker, $grant->surveySentAt);
    }

    public function test_a_cancellation_with_a_future_period_end_keeps_the_account_enabled(): void
    {
        $profile = $this->profile();

        ($this->handler($profile))(
            $this->command('canceled', eventType: 'customer.subscription.deleted', currentPeriodEnd: '+10 days'),
        );

        self::assertNull($profile->user->disabledAt);
        self::assertTrue($profile->hasCurrentSubscription(new \DateTimeImmutable()));
    }

    /**
     * An abandoned 3D Secure prompt leaves an `incomplete` subscription, which
     * stores as `canceled`. Stripe may already have stamped a period end on it,
     * and that period was never paid for, so it grants nothing.
     */
    public function test_an_incomplete_subscription_grants_no_access_despite_a_future_period_end(): void
    {
        $profile = $this->profile();

        ($this->handler($profile))($this->command('incomplete', currentPeriodEnd: '+10 days'));

        self::assertFalse($profile->hasCurrentSubscription(new \DateTimeImmutable()));
    }

    /**
     * The whole log line, not only its message: the sink is what puts the
     * record back into the log stream the handler used to write to directly.
     */
    public function test_the_log_line_the_sink_emits_carries_the_reenable_record(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');

        ($this->handler($profile))($this->command('active'));

        self::assertCount(1, $this->audit->domainChannel->records);
        self::assertSame([
            'userId' => (string) $profile->user->id,
            'outcome' => 'success',
            'channel' => AuditChannel::System->value,
            'subjectType' => 'user',
            'subjectId' => (string) $profile->user->id,
        ], $this->audit->domainChannel->records[0]['context']);
    }

    /**
     * The handler keeps its logger for the webhook diagnostics, so the
     * reflection check cannot apply. Both migrated operations must reach the
     * log stream through the sink alone.
     */
    public function test_the_account_operations_are_never_logged_directly(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');
        $logger = new RecordingLogger();

        $handler = $this->handler($profile, logger: $logger);
        $handler($this->command('active', '2026-07-25 12:00:05', 'evt_first'));
        $handler($this->command('canceled', '2026-07-25 12:00:06', 'evt_second', 'customer.subscription.deleted', '-1 hour'));

        self::assertSame(
            ['billing.account.reenabled', 'billing.account.disabled_on_cancel'],
            $this->audit->operations(),
        );

        DirectLogging::assertOperationNotLoggedBy($logger, 'billing.account.reenabled');
        DirectLogging::assertOperationNotLoggedBy($logger, 'billing.account.disabled_on_cancel');
    }

    /** The sync line stays a diagnostic: it names Stripe objects and nothing else. */
    public function test_the_sync_diagnostic_still_logs_directly_and_is_not_recorded(): void
    {
        $profile = $this->profile();
        $logger = new RecordingLogger();

        ($this->handler($profile, logger: $logger))($this->command('active'));

        $messages = array_map(static fn (array $entry): string => $entry['message'], $logger->records);
        self::assertContains('billing.subscription.synced', $messages);
        self::assertSame([], $this->audit->operations());
    }

    /**
     * A webhook has no logged-in identity, so nothing resolves an actor. The
     * record names the account it happened to and stops there.
     */
    public function test_a_webhook_record_names_no_actor(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');

        ($this->handler($profile))($this->command('active'));

        $record = $this->audit->record('billing.account.reenabled');
        self::assertNull($record->actor);
        self::assertNull($record->actorIdentifier);
        self::assertNull($record->credential);
    }

    /** Stripe ids belong to the diagnostics, never to a record. */
    public function test_a_record_carries_no_stripe_identifier(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');

        ($this->handler($profile))($this->command('active'));

        $context = $this->audit->record('billing.account.reenabled')->context;
        self::assertSame([], array_filter(
            $context,
            static fn (string|int|float|bool|null $value): bool => \is_string($value)
                && (str_starts_with($value, 'cus_') || str_starts_with($value, 'sub_') || str_starts_with($value, 'evt_')),
        ));
    }

    /**
     * The sink drains outside the business transaction, so a record made
     * inside one outlives its rollback. A commit that fails after the
     * re-enable must therefore leave no record claiming the account was
     * re-enabled.
     */
    public function test_a_commit_that_fails_after_the_re_enable_records_nothing(): void
    {
        $profile = $this->profile();
        $profile->user->disabledAt = new \DateTimeImmutable('-2 days');

        $handler = $this->handler($profile, em: $this->failingCommitEntityManager());

        try {
            $handler($this->command('active'));
            self::fail('a failed commit must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('commit failed', $e->getMessage());
        }

        self::assertSame([], $this->audit->operations());
        self::assertSame([], $this->audit->domainLogLines());
    }

    /** The mirror of the re-enable case, on the cancellation path. */
    public function test_a_commit_that_fails_after_the_cancel_disable_records_nothing(): void
    {
        $profile = $this->profile();

        $handler = $this->handler($profile, em: $this->failingCommitEntityManager());

        try {
            $handler($this->command('canceled', eventType: 'customer.subscription.deleted', currentPeriodEnd: '-1 hour'));
            self::fail('a failed commit must propagate');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->audit->operations());
    }

    /**
     * Runs the closure, then throws as a failing flush or commit would: the
     * state change has happened in memory and nothing was kept.
     */
    private function failingCommitEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback) use ($em): never {
                $callback($em);

                throw new \RuntimeException('commit failed');
            },
        );

        return $em;
    }
}
